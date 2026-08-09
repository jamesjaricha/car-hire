<?php

declare(strict_types=1);

namespace App\Services\Refunds;

use App\Contracts\AuditLoggerContract;
use App\Contracts\BookingLedgerContract;
use App\Contracts\RefundDisbursementServiceContract;
use App\DataTransferObjects\AuditEntry;
use App\DataTransferObjects\RefundDisbursementResult;
use App\Enums\AuditAction;
use App\Enums\RefundStatus;
use App\Enums\StaffPermission;
use App\Exceptions\RefundNotDisbursableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\RefundDisbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The moment money leaves.
 *
 * HOW DOUBLE DISBURSEMENT IS PREVENTED
 *
 * Not by the check in this class. By the unique key on
 * `refund_disbursements.refund_id`.
 *
 * Spec §9.3: "Never allow the same refund to be disbursed twice." The obvious
 * design puts `disbursed_at` on the refund row, at which point paying twice is
 * an UPDATE — and no index refuses a second UPDATE. The best such a design
 * manages is read-then-write, which is exactly what loses when two managers work
 * the approvals screen on the same morning: both read "approved, not yet paid",
 * both hand over cash.
 *
 * As an INSERT against a unique key, the database refuses the second writer
 * however the race falls out, and regardless of what any future caller forgets
 * to check. This is the same argument as `payment_confirmations`, pointed at
 * money going the other way — and it is the stronger case of the two, because a
 * duplicated confirmation overstates what a customer paid, while a duplicated
 * disbursement is cash that has actually left the building.
 *
 * The lock-and-check below is courtesy: it produces "already paid out by Mary at
 * 14:32, reference MM-4471" instead of a raw constraint error. Both paths raise
 * the same exception on purpose — staff should not be able to tell which one
 * caught them.
 *
 * LOCK ORDER
 *
 * Booking, then refund. Same order as `RefundRequestService` and the same order
 * `PaymentConfirmationService` takes for booking-then-payment. Reversing it here
 * would introduce a cycle between two services that routinely run at once.
 *
 * WHO MAY DO THIS — A DECISION, NOT A TRANSCRIPTION
 *
 * §12 lists `refunds.request` and `refunds.approve` and no third permission, so
 * the specification does not say who hands the money over. This service requires
 * `refunds.approve`, which puts payout at Branch Manager and above.
 *
 * The alternative reading is that a counter clerk should be able to hand back
 * cash, since they already collect and refund security deposits. That may well
 * be what the operator wants, and it would be a one-line change — but the safer
 * default is the one where the person releasing money is the one §9.3 already
 * trusts to authorise it. Recorded in `docs/OPEN-ITEMS.md` for the operator to
 * settle rather than left as an assumption nobody wrote down.
 */
final class RefundDisbursementService implements RefundDisbursementServiceContract
{
    public function __construct(
        private readonly BookingLedgerContract $ledger,
        private readonly AuditLoggerContract $audit,
    ) {}

    public function disburse(
        User $actor,
        Refund $refund,
        string $disbursementReference,
        ?string $notes = null,
    ): RefundDisbursementResult {
        // Before any lock. Nothing about the answer depends on the refund's
        // state, and a refusal should not queue behind other transactions.
        if (! $actor->hasPermissionTo(StaffPermission::RefundsApprove)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::RefundsApprove);
        }

        $disbursementReference = trim($disbursementReference);

        if ($disbursementReference === '') {
            throw RefundNotDisbursableException::referenceRequired((int) $refund->getKey());
        }

        return DB::transaction(function () use ($actor, $refund, $disbursementReference, $notes): RefundDisbursementResult {
            $booking = Booking::query()->whereKey($refund->booking_id)->lockForUpdate()->first();

            if (! $booking instanceof Booking) {
                throw RefundNotDisbursableException::statusForbidsIt((int) $refund->getKey(), $refund->status);
            }

            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Refund) {
                throw RefundNotDisbursableException::statusForbidsIt((int) $refund->getKey(), $refund->status);
            }

            // Re-read under the lock. Approval may have been granted — or the
            // refund rejected — between the caller loading it and us arriving.
            if (! $locked->status->canBeDisbursed()) {
                throw RefundNotDisbursableException::statusForbidsIt((int) $locked->getKey(), $locked->status);
            }

            // A locking read, and `limit(1)->get()` rather than `exists()`:
            // Laravel compiles exists() into `select exists(<subquery>)`, and a
            // FOR UPDATE inside that subquery is not reliably honoured.
            $existing = RefundDisbursement::query()
                ->where('refund_id', $locked->getKey())
                ->lockForUpdate()
                ->limit(1)
                ->get()
                ->first();

            if ($existing instanceof RefundDisbursement) {
                throw RefundNotDisbursableException::alreadyDisbursed((int) $locked->getKey(), $existing);
            }

            $now = CarbonImmutable::now();

            try {
                $disbursement = RefundDisbursement::query()->create([
                    'refund_id' => $locked->getKey(),
                    'disbursed_by_user_id' => $actor->getKey(),
                    // The counter the money was handed over at, taken from the
                    // acting staff member rather than from the booking's pickup
                    // branch — which may be somewhere else entirely.
                    'branch_id' => $actor->branch_id,
                    // The frozen figure. Never recomputed, never typed: a second
                    // person approved this exact amount.
                    'amount_disbursed' => $locked->amount,
                    'disbursement_reference' => $disbursementReference,
                    'disbursed_at' => $now,
                    'notes' => $notes,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The guarantee firing. A racer beat us between the check above
                // and this insert — the window an application check cannot
                // close, and the reason the constraint exists.
                throw RefundNotDisbursableException::alreadyDisbursed((int) $locked->getKey());
            }

            $locked->forceFill(['status' => RefundStatus::Disbursed])->save();

            // Now the money has actually gone, so the booking has been paid less
            // than it was a moment ago. Recomputed from scratch by the one class
            // that owns that arithmetic — the same one the confirmation service
            // uses, so the two cannot give different answers.
            $position = $this->ledger->positionFor($booking);

            $booking->forceFill([
                'amount_paid' => $position->amountPaid,
                'balance_due' => $position->balanceDue,
                'payment_status' => $position->paymentStatus,
            ])->save();

            $this->audit->record(new AuditEntry(
                action: AuditAction::RefundDisbursed,
                actor: $actor,
                booking: $booking,
                entity: $locked,
                statusBefore: RefundStatus::Approved,
                statusAfter: RefundStatus::Disbursed,
                amount: $locked->amount,
                // §9.3's proof, in the append-only record as well as on the
                // disbursement row. This is the field somebody will be asked for
                // when a customer says the money never arrived.
                paymentReference: $disbursementReference,
                paymentMethod: $locked->method,
                notes: $notes,
                metadata: [
                    'refund_id' => (int) $locked->getKey(),
                    'requested_by_user_id' => (int) $locked->requested_by_user_id,
                    'approved_by_user_id' => (int) $locked->approved_by_user_id,
                    'amount_paid_after' => $position->amountPaid,
                    'balance_due_after' => $position->balanceDue,
                    'booking_payment_status' => $position->paymentStatus->value,
                ],
            ));

            return new RefundDisbursementResult(
                refund: $locked,
                disbursement: $disbursement,
                booking: $booking,
                amountPaid: $position->amountPaid,
                balanceDue: $position->balanceDue,
                paymentStatus: $position->paymentStatus,
            );
        }, attempts: 3);
    }
}
