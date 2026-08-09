<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\RefundQuote;
use App\Enums\RefundReason;
use App\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * Spec §9's arithmetic, and nothing else.
 */
interface RefundCalculatorContract
{
    /**
     * What this booking's customer is owed under the given reason.
     *
     * Writes nothing. `$asOf` decides which side of the §9.1 notice window the
     * request falls on, and defaults to now.
     */
    public function quote(Booking $booking, RefundReason $reason, ?CarbonImmutable $asOf = null): RefundQuote;
}
