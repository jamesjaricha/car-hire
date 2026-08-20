<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\AuditLoggerContract;
use App\Contracts\BookingLedgerContract;
use App\Contracts\BookingNotifierContract;
use App\Contracts\BookingStateMachineContract;
use App\Contracts\PaymentConfirmationServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\AuditEntry;
use App\DataTransferObjects\PaymentConfirmationResult;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\SettingKey;
use App\Enums\StaffPermission;
use App\Enums\TransitionActor;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\PaymentNotConfirmableException;
use App\Exceptions\PaymentNotRecordableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentConfirmation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The moment money becomes real.
 *
 * HOW DOUBLE CONFIRMATION IS PREVENTED
 *
 * Not by the check in this class. By the unique key on
 * `payment_confirmations.payment_id`.
 *
 * The obvious design puts `confirmed_at` and `confirmed_by_user_id` on the
 * payment row, at which point confirming twice is an UPDATE — and no index
 * refuses a second UPDATE. The best such a design manages is read-then-write,
 * which is exactly what loses when two staff hit confirm in the same instant:
 * both read "not yet confirmed", both write, and the customer's money is
 * counted twice.
 *
 * As an INSERT against a unique key, the database refuses the second writer
 * however the race falls out, and regardless of what any future caller forgets
 * to check. Spec §12 asks for structurally impossible; this is what that means.
 *
 * The lock-and-check below is still worth having, but it is courtesy rather
 * than the mechanism: it produces "already confirmed by Mary at 14:32" instead
 * of a raw constraint error. Both paths raise the same exception on purpose —
 * staff should not be able to tell which one caught them.
 *
 * LOCK ORDER
 *
 * Booking first, then payment. `PaymentReferenceGenerator` takes the same two
 * in the same order, so transactions holding both queue behind one another
 * rather than deadlocking. Reversing it here would introduce a cycle.
 *
 * WHAT THIS NEVER DOES
 *
 * It never assigns a booking status directly. It recomputes the balance, works
 * out what the booking is now entitled to be, and asks BookingStateMachine
 * whether that move is allowed. A cross-border booking goes to
 * `awaiting_cross_border` rather than `confirmed` (spec §7.3, §11), and that
 * rule lives in one place because it is written down in one place.
 */
final class PaymentConfirmationService implements PaymentConfirmationServiceContract
{
    public function __construct(
        private readonly BookingStateMachineContract $stateMachine,
        private readonly VehicleHoldServiceContract $holds,
        private readonly SettingsRepositoryContract $settings,
        private readonly BookingLedgerContract $ledger,
        private readonly AuditLoggerContract $audit,
        private readonly BookingNotifierContract $notifier,
    ) {}

    public function confirm(
        User $actor,
        Payment $payment,
        string $amountReceived,
        ?string $notes = null,
    ): PaymentConfirmationResult {
        // Permission first, before any lock is taken. Nothing about the answer
        // depends on the payment's current state — the method code cannot
        // change — and a refusal should not queue behind other transactions.
        $this->assertMayConfirm($actor, $payment);

        $amountReceived = Money::of($amountReceived);

        if (! Money::isPositive($amountReceived)) {
            throw PaymentNotRecordableException::amountNotPositive($amountReceived);
        }

        $bookingId = $payment->booking_id;

        if ($bookingId === null) {
            throw PaymentNotConfirmableException::notAttributed($payment->payment_reference);
        }

        $result = DB::transaction(function () use ($actor, $payment, $bookingId, $amountReceived, $notes): PaymentConfirmationResult {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();

            if (! $booking instanceof Booking) {
                throw PaymentNotConfirmableException::notAttributed($payment->payment_reference);
            }

            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Payment) {
                throw PaymentNotConfirmableException::notAttributed($payment->payment_reference);
            }

            // Re-read under the lock. Somebody may have reattributed this
            // receipt between the caller loading it and us getting here, and
            // applying it to the wrong customer's balance is worse than
            // refusing.
            if ($locked->booking_id !== $booking->getKey()) {
                throw PaymentNotConfirmableException::bookingChangedUnderneath($locked->payment_reference);
            }

            if (! $locked->status->isConfirmable()) {
                throw PaymentNotConfirmableException::statusForbidsIt($locked->payment_reference, $locked->status);
            }

            // The booking must still be one that money means something against.
            // A receipt matched to a booking the sweep cancelled last night can
            // otherwise be confirmed: the balance would be recomputed, the
            // booking would stay cancelled, and nothing would say a refund is
            // owed. The customer's money would exist only in the payments table.
            if (! $booking->status->canAcceptPayment()) {
                throw PaymentNotConfirmableException::bookingCannotAcceptPayment(
                    $locked->payment_reference,
                    $booking->status,
                );
            }

            // Re-asserted against the row as it actually is. The check before
            // the transaction used the caller's copy, which is the right place
            // to refuse cheaply — but the permission depends on the payment
            // method, and this service must not be the thing that assumes no
            // future code path can change one.
            $this->assertMayConfirm($actor, $locked);

            // A locking read, and `limit(1)->get()` rather than `exists()`:
            // Laravel compiles exists() into `select exists(<subquery>)`, and
            // a FOR UPDATE inside that subquery is not reliably honoured.
            $existing = PaymentConfirmation::query()
                ->where('payment_id', $locked->getKey())
                ->lockForUpdate()
                ->limit(1)
                ->get()
                ->first();

            if ($existing instanceof PaymentConfirmation) {
                throw PaymentNotConfirmableException::alreadyConfirmed($locked->payment_reference, $existing);
            }

            $now = CarbonImmutable::now();

            try {
                $confirmation = PaymentConfirmation::query()->create([
                    'payment_id' => $locked->getKey(),
                    'confirmed_by_user_id' => $actor->getKey(),
                    'branch_id' => $actor->branch_id,
                    'amount_confirmed' => $amountReceived,
                    'confirmed_at' => $now,
                    'notes' => $notes,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The guarantee firing. A racer beat us to it between our check
                // and our insert — which is precisely the window an application
                // check cannot close, and precisely why the constraint exists.
                throw PaymentNotConfirmableException::alreadyConfirmed($locked->payment_reference);
            }

            // The amount ACTUALLY received, which is not necessarily what was
            // asked for. Written before the total is recomputed, because the
            // recomputation sums this row too.
            $locked->forceFill([
                'amount' => $amountReceived,
                'status' => PaymentStatus::Confirmed,
            ])->save();

            // Recomputed from every receipt and refund that exists, by the one
            // class that owns that arithmetic. Disbursing a refund moves the
            // same three figures, and two implementations of "how much has this
            // booking been paid" would eventually give two answers.
            $position = $this->ledger->positionFor($booking);

            $amountPaid = $position->amountPaid;
            $balanceDue = $position->balanceDue;
            $paymentStatus = $position->paymentStatus;

            $grandTotal = Money::of($booking->grand_total);
            $overpaid = Money::compare($amountPaid, $grandTotal) > 0;

            $statusBefore = $booking->status;
            $statusAfter = $this->nextBookingStatus($booking, $amountPaid, $grandTotal, $statusBefore);

            $booking->forceFill([
                'amount_paid' => $amountPaid,
                'balance_due' => $balanceDue,
                'payment_status' => $paymentStatus,
                'status' => $statusAfter,
                'confirmed_at' => $statusAfter === BookingStatus::Confirmed && $booking->confirmed_at === null
                    ? $now
                    : $booking->confirmed_at,
            ])->save();

            if ($statusAfter !== $statusBefore) {
                // The hold was created to last only as long as the payment
                // deadline. The car is now spoken for until it comes back.
                $this->holds->extendToHireEnd($booking);
            }

            $this->audit->record(new AuditEntry(
                action: AuditAction::PaymentConfirmed,
                actor: $actor,
                booking: $booking,
                entity: $locked,

                // The BOOKING's transition, not the payment's. One entry has
                // room for one before-and-after pair, and the consequential
                // change is what happened to the booking; the receipt's own
                // move to `confirmed` is implied by the action and recorded in
                // the metadata below.
                statusBefore: $statusBefore,
                statusAfter: $statusAfter,

                amount: $amountReceived,
                paymentReference: $locked->payment_reference,
                paymentMethod: $locked->payment_method_code,
                proofUploaded: $locked->proof_path !== null,
                notes: $notes,
                metadata: [
                    'payment_status_before' => PaymentStatus::AwaitingPayment->value,
                    'payment_status_after' => PaymentStatus::Confirmed->value,
                    'expected_amount' => $locked->expected_amount,
                    'amount_paid_total' => $amountPaid,
                    'balance_due' => $balanceDue,
                    'booking_payment_status' => $paymentStatus->value,
                    'shortfall' => $locked->shortfallAmount(),
                    'overpaid' => $overpaid,
                ],
            ));

            return new PaymentConfirmationResult(
                payment: $locked,
                confirmation: $confirmation,
                booking: $booking,
                amountPaid: $amountPaid,
                balanceDue: $balanceDue,
                paymentStatus: $paymentStatus,
                bookingStatusBefore: $statusBefore,
                bookingStatusAfter: $statusAfter,
                hasShortfall: $locked->hasShortfall(),
                shortfallAmount: $locked->shortfallAmount(),
                isOverpaid: $overpaid,
                overpaidAmount: $overpaid ? Money::subtract($amountPaid, $grandTotal) : Money::ZERO,
            );
        }, attempts: 3);

        // After the commit, for the same two reasons as BookingCreationService:
        // `attempts: 3` could otherwise send three emails for one confirmation,
        // and a rolled-back transaction would have told the customer their money
        // was checked when it was not.
        //
        // Sent for part payments as well as full settlement — somebody who has
        // paid the 50% deposit has done what was asked and needs to know it
        // arrived, and what is still owed.
        $this->notifier->paymentConfirmed($result);

        return $result;
    }

    /**
     * Where the booking is entitled to be now.
     *
     * TWO DIFFERENT THRESHOLDS, AND THEY ARE NOT THE SAME
     *
     * `payment_status` measures against the whole hire: anything less than the
     * grand total is `partially_paid`.
     *
     * Whether the BOOKING confirms measures against what the customer chose to
     * pay now — the full total, or the deposit. A customer who opted to pay a
     * 50% deposit and paid it has done everything asked of them, and their
     * booking confirms while their payment position stays `partially_paid`.
     *
     * A customer who sent less than they chose to pay has not. The booking
     * stays in `pending_payment` with its hold and deadline untouched, so they
     * still have until the deadline to send the rest — cancelling them for
     * underpaying, while their money sits in the operator's account, would be
     * indefensible.
     */
    private function nextBookingStatus(
        Booking $booking,
        string $amountPaid,
        string $grandTotal,
        BookingStatus $current,
    ): BookingStatus {
        if ($current !== BookingStatus::PendingPayment) {
            // Already moved on. A balance payment against a confirmed booking
            // settles the money and changes nothing else.
            return $current;
        }

        $requiredNow = $booking->pay_in_full
            ? $grandTotal
            : Money::of($booking->booking_deposit_amount);

        if (Money::compare($amountPaid, $requiredNow) < 0) {
            return $current;
        }

        // Spec §7.3 and §11: payment confirmation does not auto-confirm a
        // cross-border booking. It waits for the authorisation letter, the TIP
        // paperwork and the insurance extension.
        $target = $booking->isCrossBorder()
            ? BookingStatus::AwaitingCrossBorder
            : BookingStatus::Confirmed;

        // Asked, not assumed. The status is assigned by this service only after
        // the state machine has agreed the move is one the specification
        // permits, and from the right kind of actor.
        $this->stateMachine->assertCanTransition($current, $target, TransitionActor::Staff);

        return $target;
    }

    /**
     * Spec §12's per-method grants, plus the §15.12 cash exception.
     */
    private function assertMayConfirm(User $actor, Payment $payment): void
    {
        $permission = StaffPermission::toConfirm($payment->payment_method_code);

        if ($permission === null) {
            // A card payment. Nobody confirms these by hand — a gateway would
            // confirm its own — so this is a refusal rather than a permission
            // that somebody could be granted.
            throw PaymentMethodNotAvailableException::noAdapter($payment->payment_method_code->value);
        }

        if (! $actor->hasPermissionTo($permission)) {
            throw StaffPermissionDeniedException::missing($permission);
        }

        if ($permission !== StaffPermission::PaymentsConfirmCash) {
            return;
        }

        // §12 marks a counter clerk's cash confirmation "Configurable per
        // branch", so holding the permission is necessary but not sufficient.
        // Branch managers and above are not subject to it.
        if ($actor->isExemptFromCashConfirmationSetting()) {
            return;
        }

        if ($this->settings->boolean(SettingKey::CounterClerkMayConfirmCash)) {
            return;
        }

        throw StaffPermissionDeniedException::counterClerkCashConfirmationDisabled();
    }
}
