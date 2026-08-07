<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\AuditLoggerContract;
use App\Contracts\BookingExpiryServiceContract;
use App\Contracts\BookingStateMachineContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\AuditEntry;
use App\DataTransferObjects\ExpirySweepResult;
use App\Enums\AuditAction;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransitionActor;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\VehicleHold;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The unattended half of spec §8.2: "the automatic rule must work unattended at
 * 21:00 on a Sunday".
 *
 * ONE TRANSACTION PER BOOKING, NOT ONE PER SWEEP
 *
 * A single transaction around the whole run would hold locks on every expiring
 * booking until the last one finished, block staff confirming any of them, and
 * lose the entire run to one bad row. Each booking is taken on its own.
 *
 * THE RACE THIS IS BUILT AROUND
 *
 * A staff member confirming a payment at 14:59:59 and the sweep running at
 * 15:00:00 are the same booking touched by two writers a heartbeat apart. The
 * candidate list is therefore treated as a list of *suggestions*: every booking
 * is re-read under its own lock and re-checked there, so a confirmation that
 * landed first wins and the sweep quietly moves on. Acting on the candidate
 * query's answer would cancel a booking that had just been paid for.
 *
 * WHY PART-PAID BOOKINGS ARE LEFT ALONE
 *
 * A customer can confirm less than they chose to pay, which leaves the booking
 * in `pending_payment` holding their money. Spec §8.4 says a lapsed deadline
 * cancels the booking, but it plainly assumes nothing was received. Cancelling
 * unattended here would strand real money against a cancelled booking with no
 * refund record — and spec §9.3 requires a refund to be requested and approved
 * by two different people, neither of whom is a cron job. They are left for
 * staff instead, and `Booking::scopeStalledAfterDeadline()` is the queue.
 */
final class BookingExpiryService implements BookingExpiryServiceContract
{
    /** The booking was cancelled for non-payment. */
    private const OUTCOME_EXPIRED = 'expired';

    /** Skipped: it holds money, so a person must decide. */
    private const OUTCOME_HOLDS_MONEY = 'holds_money';

    /** Skipped for a reason nobody needs to know about — usually it was paid. */
    private const OUTCOME_NO_LONGER_A_CANDIDATE = 'not_a_candidate';

    public function __construct(
        private readonly BookingStateMachineContract $stateMachine,
        private readonly VehicleHoldServiceContract $holds,
        private readonly AuditLoggerContract $audit,
    ) {}

    public function sweep(?CarbonImmutable $asOf = null): ExpirySweepResult
    {
        $asOf ??= CarbonImmutable::now();

        // Keys only. The rows are re-read under their own locks a moment later,
        // so carrying models here would only invite somebody to trust them.
        $candidates = Booking::query()
            ->pastPaymentDeadline($asOf)
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;
        $leftForStaff = 0;

        foreach ($candidates as $id) {
            // Three outcomes, not two. A booking confirmed between the
            // candidate query and the lock is not "left for staff" — nothing is
            // wrong with it and nobody needs to look at it. Counting every skip
            // as one would put a number in the nightly log that means "somebody
            // must deal with these" when most of them were simply paid, and a
            // monitoring signal that cries wolf is worse than none.
            match ($this->expireOne((int) $id, $asOf)) {
                self::OUTCOME_EXPIRED => $expired++,
                self::OUTCOME_HOLDS_MONEY => $leftForStaff++,
                default => null,
            };
        }

        // The guideline's safety net, run whether or not anything expired: a
        // hold whose booking was already dealt with by other means would
        // otherwise keep a vehicle off sale for as long as the row survived.
        $holdsReleased = $this->holds->releaseExpired($asOf);

        return new ExpirySweepResult(
            expired: $expired,
            leftForStaff: $leftForStaff,
            holdsReleased: $holdsReleased,
        );
    }

    /**
     * @return self::OUTCOME_* what became of this booking
     */
    private function expireOne(int $bookingId, CarbonImmutable $asOf): string
    {
        return DB::transaction(function () use ($bookingId, $asOf): string {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();

            if (! $booking instanceof Booking) {
                return self::OUTCOME_NO_LONGER_A_CANDIDATE;
            }

            // Every one of these was true when the candidate query ran. They
            // are checked again because the seconds in between are exactly when
            // a staff member confirms a payment.
            if ($booking->status !== BookingStatus::PendingPayment) {
                return self::OUTCOME_NO_LONGER_A_CANDIDATE;
            }

            if ($booking->payment_deadline_at === null
                || $booking->payment_deadline_at->greaterThan($asOf)) {
                return self::OUTCOME_NO_LONGER_A_CANDIDATE;
            }

            // Somebody's money is in this booking. Not a decision for a cron.
            if (Money::isPositive($booking->amount_paid)) {
                return self::OUTCOME_HOLDS_MONEY;
            }

            $statusBefore = $booking->status;

            // Asked, not assumed — and asked as the system, which is the only
            // actor spec §7.3 permits for this transition.
            $this->stateMachine->assertCanTransition(
                $statusBefore,
                BookingStatus::CancelledNonPayment,
                TransitionActor::System,
            );

            $expiredReferences = $this->expirePayments($booking, $asOf);

            $booking->forceFill([
                'status' => BookingStatus::CancelledNonPayment,
                'payment_status' => BookingPaymentStatus::PaymentExpired,
                'cancelled_at' => $asOf,
                'cancellation_reason' => 'Payment deadline passed without confirmation.',
            ])->save();

            $this->releaseHolds($booking);

            $this->audit->record(new AuditEntry(
                action: AuditAction::BookingCancelledNonPayment,
                // No actor: nobody decided this, the clock did. AuditEntry
                // derives is_automatic from exactly that.
                booking: $booking,
                statusBefore: $statusBefore,
                statusAfter: BookingStatus::CancelledNonPayment,
                notes: 'Cancelled automatically: the payment deadline passed with nothing confirmed.',
                metadata: [
                    'payment_deadline_at' => $booking->payment_deadline_at->toIso8601String(),
                    'swept_at' => $asOf->toIso8601String(),
                    'expired_payments' => $expiredReferences,
                ],
            ));

            return self::OUTCOME_EXPIRED;
        }, attempts: 3);
    }

    /**
     * Mark the booking's outstanding receipts expired.
     *
     * Confirmed receipts are left alone. Money that arrived stays arrived, and
     * this method is only reached when there is none — but leaving the filter
     * explicit means that stays true if the guard above is ever relaxed.
     *
     * @return list<string>
     */
    private function expirePayments(Booking $booking, CarbonImmutable $asOf): array
    {
        $payments = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->outstanding()
            ->lockForUpdate()
            ->get();

        $references = [];

        foreach ($payments as $payment) {
            $statusBefore = $payment->status;

            $payment->forceFill(['status' => PaymentStatus::PaymentExpired])->save();

            $references[] = $payment->payment_reference;

            $this->audit->record(new AuditEntry(
                action: AuditAction::PaymentExpired,
                booking: $booking,
                entity: $payment,
                statusBefore: $statusBefore,
                statusAfter: PaymentStatus::PaymentExpired,
                amount: $payment->expected_amount,
                paymentReference: $payment->payment_reference,
                paymentMethod: $payment->payment_method_code,
                proofUploaded: $payment->proof_path !== null,
                metadata: ['swept_at' => $asOf->toIso8601String()],
            ));
        }

        return $references;
    }

    /**
     * Spec §8.4: the hold is released immediately.
     *
     * Through VehicleHoldService, which is the only code permitted to write to
     * `vehicle_holds`. The sweep's own `releaseExpired()` pass would catch
     * these eventually, but "immediately" is what the specification says and a
     * vehicle held against a cancelled booking is inventory nobody can sell.
     */
    private function releaseHolds(Booking $booking): void
    {
        $holds = VehicleHold::query()
            ->where('booking_id', $booking->getKey())
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            $this->holds->release($hold);
        }
    }
}
