<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\DateRange;
use App\Models\Vehicle;

/**
 * Resolves what a vehicle costs, honouring the class → vehicle override chain.
 *
 * Every return value is a bcmath-safe decimal string at the configured scale.
 * Nothing here returns a float, and callers must not cast one.
 */
interface PricingServiceContract
{
    public function dailyRateFor(Vehicle $vehicle): string;

    /**
     * The refundable cash deposit taken at the counter against damage.
     * Not the booking deposit — that part-pays the hire and is a percentage
     * of the grand total.
     */
    public function securityDepositFor(Vehicle $vehicle): string;

    public function insuranceExcessFor(Vehicle $vehicle): string;

    public function turnaroundBufferMinutesFor(Vehicle $vehicle): int;

    public function hireTotal(Vehicle $vehicle, DateRange $range): string;

    /**
     * Mandatory damage waiver for the hire. Priced per day or flat per booking
     * depending on the vehicle class.
     */
    public function insuranceTotal(Vehicle $vehicle, DateRange $range): string;
}
