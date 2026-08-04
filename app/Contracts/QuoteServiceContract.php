<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\DateRange;
use App\DataTransferObjects\Quote;
use App\DataTransferObjects\QuoteOptions;
use App\Models\Vehicle;

/**
 * Prices a hire.
 *
 * There is exactly one implementation and exactly one entry point, deliberately.
 * Spec §1.2 requires the price in search results to equal the price at
 * checkout; the surest way to break that is to have search and checkout each
 * compute their own total. Both call this, and the basket stores the result.
 */
interface QuoteServiceContract
{
    public function quoteFor(
        Vehicle $vehicle,
        DateRange $range,
        ?QuoteOptions $options = null,
    ): Quote;
}
