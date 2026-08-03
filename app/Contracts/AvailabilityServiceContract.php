<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\DateRange;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Support\Collection;

/**
 * Answers which vehicles are free for a window.
 *
 * Important: a positive answer here is advisory, not a reservation. Between
 * this check and a hold being placed, another customer may take the vehicle.
 * Only VehicleHoldService::place() decides, and it re-checks under a lock.
 * Treating an availability result as a guarantee is how double-bookings happen.
 */
interface AvailabilityServiceContract
{
    public function isVehicleAvailable(Vehicle $vehicle, DateRange $range): bool;

    /**
     * Bookable vehicles at a branch for the window, optionally of one class.
     *
     * @return Collection<int, Vehicle>
     */
    public function availableVehicles(
        Branch $branch,
        DateRange $range,
        ?VehicleClass $class = null,
    ): Collection;
}
