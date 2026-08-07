<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of ONE receipt.
 *
 * Not to be confused with BookingPaymentStatus, which is the booking's payment
 * position as a whole. Spec §7.1 lists a single set of payment states, but they
 * mix the two ideas: `proof_submitted` describes a receipt, while
 * `partially_paid` describes a booking — an individual K500 cash payment is
 * confirmed or it is not, and is never "partially paid".
 *
 * Keeping them apart means a row's status never depends on rows it knows
 * nothing about. Under the merged reading, confirming a balance payment would
 * have to reach back and rewrite the earlier deposit row from `partially_paid`
 * to `paid_in_full`, which is both surprising and a second writer to a record
 * that ought to be settled history.
 *
 * Spec §7 is explicit that booking states and payment states are two separate
 * entities that must not be merged. This is the same principle one level down.
 */
enum PaymentStatus: string
{
    /** Instructions issued, nothing received. */
    case AwaitingPayment = 'awaiting_payment';

    /**
     * The customer has uploaded something they say is proof.
     *
     * Spec §14.3 and the developer guideline are both emphatic: this is NOT
     * confirmation and must never be shown to the customer as "payment
     * received". Doctored screenshots are common. Only a staff confirmation
     * moves money.
     */
    case ProofSubmitted = 'proof_submitted';

    /** A staff member has verified that this money actually arrived. */
    case Confirmed = 'confirmed';

    /** The deadline passed without confirmation. Spec §8.4. */
    case PaymentExpired = 'payment_expired';

    /** A refund has been approved but not yet disbursed. */
    case RefundPending = 'refund_pending';

    /** The money has gone back to the customer. */
    case Refunded = 'refunded';

    /**
     * Whether this receipt's amount counts towards what the customer has paid.
     *
     * `RefundPending` counts. A refund that has been approved but not disbursed
     * means the operator is still holding the money — treating it as already
     * gone would show a balance the customer does not owe, and could let a
     * booking be cancelled for non-payment while their cash sits in the till.
     * Only actual disbursement removes it.
     */
    public function countsTowardsAmountPaid(): bool
    {
        return match ($this) {
            self::Confirmed, self::RefundPending => true,
            self::AwaitingPayment, self::ProofSubmitted, self::PaymentExpired, self::Refunded => false,
        };
    }

    /**
     * Whether this receipt is still waiting on someone.
     */
    public function isOutstanding(): bool
    {
        return match ($this) {
            self::AwaitingPayment, self::ProofSubmitted => true,
            self::Confirmed, self::PaymentExpired, self::RefundPending, self::Refunded => false,
        };
    }

    /**
     * Whether a staff member may still confirm this receipt.
     *
     * An expired or refunded payment is not confirmable, and an already
     * confirmed one is refused structurally by the unique key on
     * payment_confirmations rather than by this check.
     */
    public function isConfirmable(): bool
    {
        return match ($this) {
            self::AwaitingPayment, self::ProofSubmitted => true,
            self::Confirmed, self::PaymentExpired, self::RefundPending, self::Refunded => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::ProofSubmitted => 'Proof submitted, not yet verified',
            self::Confirmed => 'Confirmed',
            self::PaymentExpired => 'Expired',
            self::RefundPending => 'Refund pending',
            self::Refunded => 'Refunded',
        };
    }
}
