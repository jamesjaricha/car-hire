<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Vehicle;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;

/**
 * The only sanctioned way to claim a vehicle.
 *
 * `place()` is the chokepoint that makes double-booking impossible. Any other
 * code path that inserts into `vehicle_holds` bypasses the row lock and
 * reintroduces the race this whole design exists to prevent.
 */
interface VehicleHoldServiceContract
{
    /**
     * Claim a vehicle for a window, or fail.
     *
     * @throws VehicleNotAvailableException when the vehicle is
     *                                      out of service or the window is already claimed.
     */
    public function place(
        Vehicle $vehicle,
        DateRange $range,
        CarbonImmutable $expiresAt,
        ?int $bookingId = null,
    ): VehicleHold;

    public function release(VehicleHold $hold): void;

    /**
     * Release every hold whose payment deadline has passed.
     *
     * Returns the number released. Runs on a schedule, and is also exposed as a
     * manual admin action: if the scheduled run dies unnoticed, vehicles would
     * otherwise stay claimed and inventory would quietly disappear.
     */
    public function releaseExpired(?CarbonImmutable $asOf = null): int;
}
