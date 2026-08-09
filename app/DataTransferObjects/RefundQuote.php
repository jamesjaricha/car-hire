<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\RefundReason;
use App\Support\Money;

/**
 * What spec §9 says a customer is owed, and how that figure was reached.
 *
 * Every component is carried, not just the total. A refund is a number somebody
 * will be asked to justify to a customer on the telephone — "you paid K2,310,
 * we kept the K1,155 deposit because you cancelled inside 24 hours, and the
 * admin fee is K150" — and a DTO that answered only "K1,005" would make that
 * conversation impossible from the record.
 *
 * These values are also what gets frozen onto the refund row. See the migration
 * for why they are stored rather than recomputed.
 */
final class RefundQuote
{
    public function __construct(
        public readonly RefundReason $reason,

        /** Confirmed money held against the booking when the quote was taken. */
        public readonly string $amountPaid,

        /** Withheld under spec §9.1. Zero unless the deposit is forfeit. */
        public readonly string $bookingDepositRetained,

        /** The fee as configured, before clamping. Kept to explain a clamp. */
        public readonly string $adminFeeConfigured,

        /** The fee actually taken off, which is never more than remained. */
        public readonly string $adminFeeDeducted,

        /** What the customer gets. Never negative. */
        public readonly string $amount,

        /**
         * Whether the fee above came from a §15.1 placeholder rather than a
         * decision. Carried so screens can say so and so the refund row keeps
         * saying so after the operator enters a real figure.
         */
        public readonly bool $adminFeeIsPlaceholder,

        /** Whether the request falls inside the §9.1 deposit-forfeit window. */
        public readonly bool $insideNoticeWindow,

        /** The window's width when the quote was taken. Spec §9.1: 24 hours. */
        public readonly int $noticeWindowHours,
    ) {}

    /**
     * Whether there is any money to give back.
     *
     * False is a legitimate outcome, not a failure: a customer who paid a
     * deposit and cancelled two hours before pickup forfeits exactly that
     * deposit and is owed nothing. The refund is still recorded, because "we
     * considered it and nothing was due" is a different statement from silence,
     * and the customer will ask.
     */
    public function hasAnythingToRefund(): bool
    {
        return Money::isPositive($this->amount);
    }

    /**
     * Whether the configured fee had to be reduced to avoid refunding a
     * negative amount.
     *
     * Happens when what remains after the deposit is smaller than the fee. The
     * customer is not billed the difference — spec §9 describes deductions from
     * money held, not a charge.
     */
    public function adminFeeWasClamped(): bool
    {
        return Money::compare($this->adminFeeDeducted, $this->adminFeeConfigured) < 0;
    }
}
