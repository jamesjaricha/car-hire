<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\BookingLedgerContract;
use App\DataTransferObjects\LedgerPosition;
use App\Enums\BookingPaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;

/**
 * `amount_paid` = SUM(confirmed receipts) − SUM(disbursed refunds).
 *
 * WHY THIS IS A CLASS RATHER THAN A METHOD ON WHOEVER NEEDS IT
 *
 * Two services now change what a booking has been paid: confirming a receipt
 * adds to it, disbursing a refund takes from it. Written twice, the two
 * implementations agree until one is changed — and the failure mode is not an
 * exception, it is a booking whose stated balance depends on which service
 * touched it last. Money that disagrees with itself is the worst kind of bug in
 * this system, because it looks plausible from every screen.
 *
 * So the arithmetic lives here, and both callers write what it returns.
 *
 * RECOMPUTED, NEVER INCREMENTED
 *
 * The total is always the full sum of the rows that count, derived from scratch.
 * Adding a delta is wrong the first time anything is confirmed twice, corrected
 * or replayed, and wrong silently. Recomputation can also be checked against the
 * till, which an increment cannot.
 *
 * APPROVED IS NOT DISBURSED
 *
 * Only refunds that have actually been paid out are subtracted. A refund sitting
 * approved but unpaid is money still in the operator's hands, and treating it as
 * gone would show the customer a balance they do not owe — and could let a
 * booking be cancelled for non-payment while their cash is still in the till.
 * `PaymentStatus::countsTowardsAmountPaid()` takes the same position from the
 * other direction, and the two must not drift apart.
 *
 * TWO MECHANICAL TRAPS, BOTH PREVIOUSLY PAID FOR
 *
 * `reorder()` before an aggregate: MySQL rejects a `SELECT SUM(...)` carrying an
 * ORDER BY on a column outside the aggregate (error 1140) where SQLite passes it
 * silently. `Money::of()` after: SQL returns '1655', not '1655.00', and the
 * difference fails every exact-string assertion in the suite.
 */
final class BookingLedger implements BookingLedgerContract
{
    public function positionFor(Booking $booking): LedgerPosition
    {
        $bookingId = $booking->getKey();

        $confirmedTotal = Money::of(
            Payment::query()
                ->where('booking_id', $bookingId)
                ->counted()
                ->reorder()
                ->sum('amount')
        );

        $disbursedTotal = Money::of(
            Refund::query()
                ->where('booking_id', $bookingId)
                ->disbursed()
                ->reorder()
                ->sum('amount')
        );

        // Clamped. A booking cannot have been paid a negative amount, and
        // refunds are computed from what was held, so this should be
        // unreachable — but a stored figure that has gone below zero is a fault
        // worth failing safe on rather than propagating into a balance.
        $amountPaid = Money::compare($confirmedTotal, $disbursedTotal) > 0
            ? Money::subtract($confirmedTotal, $disbursedTotal)
            : Money::ZERO;

        $grandTotal = Money::of($booking->grand_total);

        // Also clamped: an overpayment is a refund question, and rendering it as
        // "balance: -200.00" invites somebody to treat it as a debt.
        $balanceDue = Money::compare($amountPaid, $grandTotal) >= 0
            ? Money::ZERO
            : Money::subtract($grandTotal, $amountPaid);

        return new LedgerPosition(
            confirmedTotal: $confirmedTotal,
            disbursedTotal: $disbursedTotal,
            amountPaid: $amountPaid,
            balanceDue: $balanceDue,
            paymentStatus: $this->paymentStatusFor($booking, $amountPaid, $grandTotal),
        );
    }

    /**
     * Spec §7.1, as a function of what has been confirmed and what has gone back.
     *
     * REFUND STATES ARE TESTED FIRST, AND ON ROWS RATHER THAN ON AMOUNTS
     *
     * §7.1 gives `refund_pending` and `refunded` as payment positions, and they
     * describe something the plain paid/unpaid scale cannot: a fully refunded
     * booking has an `amount_paid` of zero, which is indistinguishable from a
     * booking that never paid at all. Those two need very different handling —
     * one is chased for money, the other has already had theirs back — so the
     * refund questions are asked before the arithmetic ones.
     *
     * The tests are on the existence of refund rows, not on their totals. A
     * total is the wrong instrument: it cannot tell "refunded in full" from
     * "refunded nothing", and it would make the answer depend on the size of
     * the refund rather than on whether one happened.
     */
    private function paymentStatusFor(
        Booking $booking,
        string $amountPaid,
        string $grandTotal,
    ): BookingPaymentStatus {
        $bookingId = $booking->getKey();

        if (Refund::query()->where('booking_id', $bookingId)->disbursed()->exists()) {
            return BookingPaymentStatus::Refunded;
        }

        // Agreed but not yet handed over. `hasConfirmedFunds()` counts this as
        // funds held, which is exactly right: the money is still here.
        if (Refund::query()->where('booking_id', $bookingId)->awaitingPayout()->exists()) {
            return BookingPaymentStatus::RefundPending;
        }

        if (! Money::isPositive($amountPaid)) {
            return BookingPaymentStatus::AwaitingPayment;
        }

        return Money::compare($amountPaid, $grandTotal) >= 0
            ? BookingPaymentStatus::PaidInFull
            : BookingPaymentStatus::PartiallyPaid;
    }
}
