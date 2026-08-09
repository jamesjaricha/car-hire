<?php

declare(strict_types=1);

namespace App\Services\Refunds;

use App\Contracts\AuditLoggerContract;
use App\Contracts\BookingLedgerContract;
use App\Contracts\RefundCalculatorContract;
use App\Contracts\RefundRequestServiceContract;
use App\DataTransferObjects\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\StaffPermission;
use App\Exceptions\RefundNotApprovableException;
use App\Exceptions\RefundNotRequestableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raising and deciding refunds. Spec §9.3, the first two steps.
 *
 * WHY REQUESTING AND DISBURSING ARE DIFFERENT SERVICES
 *
 * §9.3 puts different people on them. Modelling that as one class with three
 * methods would make the separation a matter of which method somebody calls,
 * which is exactly as strong as remembering to call the right one. Split, the
 * boundary is visible in the constructor of anything that wants both.
 *
 * THE AMOUNT IS NOT A PARAMETER
 *
 * There is no way for a caller to pass a figure. `RefundCalculator` produces it
 * from §9 and this service freezes it onto the row. That is the whole point of
 * the design: an editable refund amount makes §9 advice rather than policy, and
 * the person best placed to abuse it is the one raising the request.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It never cancels a booking. A refund and a cancellation are two decisions that
 * usually travel together and are not the same thing — a cross-border booking is
 * cancelled by the operator's own failure, a customer cancellation is theirs.
 * The panel action calls this and `BookingCancellationService` in one
 * transaction, and neither knows the other exists.
 *
 * LOCK ORDER
 *
 * Booking, then refund. The same order `PaymentConfirmationService` takes for
 * booking-then-payment, extended: transactions holding both queue behind one
 * another rather than deadlocking.
 */
final class RefundRequestService implements RefundRequestServiceContract
{
    public function __construct(
        private readonly RefundCalculatorContract $calculator,
        private readonly BookingLedgerContract $ledger,
        private readonly AuditLoggerContract $audit,
    ) {}

    public function request(
        User $actor,
        Booking $booking,
        RefundReason $reason,
        PaymentMethodCode $method,
        ?string $notes = null,
    ): Refund {
        // Before any lock: nothing about the answer depends on the booking, and
        // a refusal should not queue behind other work.
        if (! $actor->hasPermissionTo(StaffPermission::RefundsRequest)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::RefundsRequest);
        }

        return DB::transaction(function () use ($actor, $booking, $reason, $method, $notes): Refund {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Booking) {
                throw RefundNotRequestableException::alreadyOpen($booking->reference);
            }

            // A locking read, and `limit(1)->get()` rather than `exists()`:
            // Laravel compiles exists() into `select exists(<subquery>)`, and a
            // FOR UPDATE inside that subquery is not reliably honoured. Two
            // staff raising a refund on the same booking at the same moment is
            // the case this closes — the disbursement key stops one refund
            // being paid twice, but cannot see that a second refund covers the
            // same receipts.
            $open = Refund::query()
                ->where('booking_id', $locked->getKey())
                ->open()
                ->lockForUpdate()
                ->limit(1)
                ->get()
                ->first();

            if ($open instanceof Refund) {
                throw RefundNotRequestableException::alreadyOpen($locked->reference);
            }

            // Computed from the booking as it is under the lock, not from the
            // caller's copy. `amount_paid` moves whenever a receipt is
            // confirmed, and quoting from a stale figure would refund money the
            // customer never sent — or withhold money they did.
            $quote = $this->calculator->quote($locked, $reason);

            if (! $quote->hasAnythingToRefund()) {
                throw RefundNotRequestableException::nothingToRefund(
                    $locked->reference,
                    $quote->amountPaid,
                );
            }

            $now = CarbonImmutable::now();

            $refund = Refund::query()->create([
                'booking_id' => $locked->getKey(),
                'operator_id' => $locked->operator_id,
                'reason' => $reason,
                'status' => RefundStatus::Requested,
                'method' => $method,

                // Frozen. See the migration for why none of these is recomputed.
                'amount_paid_at_request' => $quote->amountPaid,
                'booking_deposit_retained' => $quote->bookingDepositRetained,
                'admin_fee_configured' => $quote->adminFeeConfigured,
                'admin_fee_deducted' => $quote->adminFeeDeducted,
                'amount' => $quote->amount,
                'admin_fee_was_placeholder' => $quote->adminFeeIsPlaceholder,
                'currency' => $locked->currency,

                'requested_by_user_id' => $actor->getKey(),
                'requested_at' => $now,
                'notes' => $notes,
            ]);

            $this->audit->record(new AuditEntry(
                action: AuditAction::RefundRequested,
                actor: $actor,
                booking: $locked,
                entity: $refund,
                // The refund's own state, not the booking's. The booking has not
                // moved — whatever cancelled it did that separately.
                statusAfter: RefundStatus::Requested,
                amount: $quote->amount,
                notes: $notes,
                metadata: $this->calculationMetadata($quote->amountPaid, $refund),
            ));

            return $refund;
        }, attempts: 3);
    }

    public function approve(User $actor, Refund $refund, ?string $notes = null): Refund
    {
        if (! $actor->hasPermissionTo(StaffPermission::RefundsApprove)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::RefundsApprove);
        }

        return DB::transaction(function () use ($actor, $refund, $notes): Refund {
            [$booking, $locked] = $this->lockBookingAndRefund($refund);

            if (! $locked->status->canBeApproved()) {
                throw RefundNotApprovableException::statusForbidsIt((int) $locked->getKey(), $locked->status);
            }

            // SPEC §9.3'S TWO-PERSON RULE.
            //
            // Re-read under the lock rather than trusted from the caller's copy.
            // The CHECK constraint on the table would also refuse this write, so
            // the guarantee does not depend on this line — but a constraint
            // violation reaching a member of staff as a raw SQL error, on a
            // fraud control, is a poor way to explain a rule that exists to
            // protect them as much as the business.
            if ((int) $locked->requested_by_user_id === (int) $actor->getKey()) {
                throw RefundNotApprovableException::sameUserRequestedIt((int) $locked->getKey());
            }

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => RefundStatus::Approved,
                'approved_by_user_id' => $actor->getKey(),
                'approved_at' => $now,
            ])->save();

            // The money has NOT moved. `amount_paid` is unchanged; what changes
            // is the booking's position, which becomes `refund_pending` — the
            // operator has agreed to give money back and is still holding it.
            $this->applyLedgerPosition($booking);

            $this->audit->record(new AuditEntry(
                action: AuditAction::RefundApproved,
                actor: $actor,
                booking: $booking,
                entity: $locked,
                statusBefore: RefundStatus::Requested,
                statusAfter: RefundStatus::Approved,
                amount: $locked->amount,
                notes: $notes,
                metadata: [
                    'requested_by_user_id' => (int) $locked->requested_by_user_id,
                    'approved_at' => $now->toIso8601String(),
                ],
            ));

            return $locked;
        }, attempts: 3);
    }

    public function reject(User $actor, Refund $refund, string $reason): Refund
    {
        // Rejecting is the same authority as approving. Somebody who may decide
        // that money leaves may also decide that it does not; a role that could
        // only say yes would be a strange thing to hold.
        if (! $actor->hasPermissionTo(StaffPermission::RefundsApprove)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::RefundsApprove);
        }

        $reason = trim($reason);

        return DB::transaction(function () use ($actor, $refund, $reason): Refund {
            [$booking, $locked] = $this->lockBookingAndRefund($refund);

            if (! $locked->status->canBeRejected()) {
                throw RefundNotApprovableException::statusForbidsIt((int) $locked->getKey(), $locked->status);
            }

            if ($reason === '') {
                throw RefundNotApprovableException::rejectionNeedsAReason((int) $locked->getKey());
            }

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => RefundStatus::Rejected,
                'rejected_by_user_id' => $actor->getKey(),
                'rejected_at' => $now,
                'rejection_reason' => $reason,
            ])->save();

            // Nothing financial changed, and the recompute is here anyway so
            // that this service always leaves the booking's stated position
            // agreeing with the rows behind it, whichever path was taken.
            $this->applyLedgerPosition($booking);

            $this->audit->record(new AuditEntry(
                action: AuditAction::RefundRejected,
                actor: $actor,
                booking: $booking,
                entity: $locked,
                statusBefore: RefundStatus::Requested,
                statusAfter: RefundStatus::Rejected,
                amount: $locked->amount,
                notes: $reason,
                metadata: [
                    'requested_by_user_id' => (int) $locked->requested_by_user_id,
                    'rejected_at' => $now->toIso8601String(),
                    // The booking stays cancelled. Rejecting the refund decides
                    // that no money goes back; it does not un-cancel the hire,
                    // and staff reading this later should not have to infer that.
                    'booking_status' => $booking->status->value,
                ],
            ));

            return $locked;
        }, attempts: 3);
    }

    /**
     * Booking first, then refund. Always this order — see the class docblock.
     *
     * @return array{0: Booking, 1: Refund}
     */
    private function lockBookingAndRefund(Refund $refund): array
    {
        $booking = Booking::query()->whereKey($refund->booking_id)->lockForUpdate()->first();

        if (! $booking instanceof Booking) {
            throw RefundNotApprovableException::statusForbidsIt((int) $refund->getKey(), $refund->status);
        }

        $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Refund) {
            throw RefundNotApprovableException::statusForbidsIt((int) $refund->getKey(), $refund->status);
        }

        return [$booking, $locked];
    }

    /**
     * Write the booking's recomputed financial position.
     *
     * Always through `BookingLedger`, never by assigning a status: approval and
     * disbursement both change what §7.1 position the booking is in, and the
     * rule for deciding that lives in one place.
     */
    private function applyLedgerPosition(Booking $booking): void
    {
        $position = $this->ledger->positionFor($booking);

        $booking->forceFill([
            'amount_paid' => $position->amountPaid,
            'balance_due' => $position->balanceDue,
            'payment_status' => $position->paymentStatus,
        ])->save();
    }

    /**
     * The §9 working, kept with the audit entry.
     *
     * The refund row carries these too, but audit entries are the record that
     * survives — §12 makes them append-only precisely so that the state of the
     * world at the time of a decision cannot be edited afterwards.
     *
     * @return array<string, mixed>
     */
    private function calculationMetadata(string $amountPaid, Refund $refund): array
    {
        return [
            'reason' => $refund->reason->value,
            'method' => $refund->method->value,
            'amount_paid_at_request' => Money::of($amountPaid),
            'booking_deposit_retained' => $refund->booking_deposit_retained,
            'admin_fee_configured' => $refund->admin_fee_configured,
            'admin_fee_deducted' => $refund->admin_fee_deducted,
            // Whether the fee applied to real money was a §15.1 placeholder.
            // Frozen here so that a refund raised before the operator decided
            // the fee stays readable as such afterwards.
            'admin_fee_was_placeholder' => $refund->admin_fee_was_placeholder,
        ];
    }
}
