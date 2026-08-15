<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\DateRange;
use App\Enums\InsurancePriceMode;
use App\Models\Vehicle;
use App\Models\VehicleClass;

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

    /**
     * Whether this vehicle's damage waiver is charged per day or flat.
     *
     * Exposed here so callers never have to reach through the class relation
     * themselves — vehicle class access stays in one place.
     */
    public function insuranceModeFor(Vehicle $vehicle): InsurancePriceMode;

    public function turnaroundBufferMinutesFor(Vehicle $vehicle): int;

    public function hireTotal(Vehicle $vehicle, DateRange $range): string;

    /**
     * Mandatory damage waiver for the hire. Priced per day or flat per booking
     * depending on the vehicle class.
     */
    public function insuranceTotal(Vehicle $vehicle, DateRange $range): string;

    /**
     * The lowest daily rate any of these vehicles actually charges.
     *
     * For browse pages that have no dates and therefore cannot quote a hire.
     * This is a DAILY RATE and must never be presented as a total — spec §1.2
     * governs the all-in price, and the only place that is computed is
     * `QuoteService` with a real `DateRange`.
     *
     * Taken across the vehicles rather than read off `vehicle_classes.daily_rate`
     * because a vehicle-level override may be higher or lower than its class.
     * Using the class figure would advertise a rate no actual car charges, which
     * is precisely the drift §1.2 exists to prevent.
     *
     * Null when the collection is empty: a class with nothing bookable in it has
     * no rate to advertise, and printing the class figure anyway would price
     * something nobody can hire.
     *
     * @param  iterable<Vehicle>  $vehicles
     */
    public function lowestDailyRate(VehicleClass $class, iterable $vehicles): ?string;
}
