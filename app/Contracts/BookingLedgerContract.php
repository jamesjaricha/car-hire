<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\LedgerPosition;
use App\Models\Booking;

/**
 * The single answer to "how much has this booking been paid".
 */
interface BookingLedgerContract
{
    /**
     * Recompute the booking's financial position from the receipts and refunds
     * that exist right now. Writes nothing.
     */
    public function positionFor(Booking $booking): LedgerPosition;
}
