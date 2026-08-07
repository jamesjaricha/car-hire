<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Specification §7.1, verbatim: a booking's payment position as a whole.
 *
 * Distinct from `BookingStatus` (§7.2), which is where the *booking* is, and
 * from `PaymentStatus`, which is where one *receipt* is. Spec §7 opens by
 * saying these are separate entities and must not be merged; this enum is the
 * middle one, and it is the one §14.3 means when it says "a 50% deposit leaves
 * the payment in partially_paid".
 *
 * It is derived, never set by hand. `PaymentConfirmationService` recomputes it
 * from the sum of confirmed receipts, in the same breath as `amount_paid` and
 * `balance_due`, so the three cannot disagree.
 */
enum BookingPaymentStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case ProofSubmitted = 'proof_submitted';
    case PartiallyPaid = 'partially_paid';
    case PaidInFull = 'paid_in_full';
    case PaymentExpired = 'payment_expired';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    /**
     * Whether the hire has been settled in full.
     *
     * Spec §14.3: a booking with an outstanding balance can never reach
     * `vehicle_released`. This is what that guard reads.
     */
    public function isSettled(): bool
    {
        return $this === self::PaidInFull;
    }

    /**
     * Whether any money at all has been confirmed against the booking.
     *
     * Spec §7.3: a booking confirms once payment — deposit or full — has been
     * confirmed. A partial deposit is enough to confirm the booking but not to
     * release the vehicle, and those two are easy to conflate.
     */
    public function hasConfirmedFunds(): bool
    {
        return match ($this) {
            self::PartiallyPaid, self::PaidInFull, self::RefundPending => true,
            self::AwaitingPayment, self::ProofSubmitted, self::PaymentExpired, self::Refunded => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::ProofSubmitted => 'Proof submitted, not yet verified',
            self::PartiallyPaid => 'Deposit paid, balance outstanding',
            self::PaidInFull => 'Paid in full',
            self::PaymentExpired => 'Payment deadline passed',
            self::RefundPending => 'Refund pending',
            self::Refunded => 'Refunded',
        };
    }
}
