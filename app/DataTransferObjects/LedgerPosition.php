<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\BookingPaymentStatus;

/**
 * Where a booking stands financially, recomputed from scratch.
 *
 * Produced by `BookingLedger` and written onto the booking by whichever service
 * caused the change. Nothing here is incremented from a previous value — see
 * the ledger for why.
 */
final class LedgerPosition
{
    public function __construct(
        /** Everything confirmed against the booking, before refunds. */
        public readonly string $confirmedTotal,

        /** Everything actually paid back out. Approved-but-unpaid is not here. */
        public readonly string $disbursedTotal,

        /** Confirmed minus disbursed, clamped at zero. */
        public readonly string $amountPaid,

        /** Still owed on the hire, clamped at zero. */
        public readonly string $balanceDue,

        public readonly BookingPaymentStatus $paymentStatus,
    ) {}
}
