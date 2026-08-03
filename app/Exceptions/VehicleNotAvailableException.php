<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DataTransferObjects\DateRange;
use App\Models\Vehicle;
use RuntimeException;

/**
 * Raised when a vehicle cannot be held for the requested window.
 *
 * This is the losing side of a race, or an attempt to book a vehicle that is
 * not in the bookable fleet. It is an expected outcome, not a system fault —
 * callers are meant to catch it and offer the customer an alternative vehicle.
 */
final class VehicleNotAvailableException extends RuntimeException
{
    public static function notBookable(Vehicle $vehicle): self
    {
        return new self(sprintf(
            'Vehicle [%s] is not bookable: status is %s.',
            $vehicle->registration,
            $vehicle->status->value,
        ));
    }

    public static function rangeAlreadyHeld(Vehicle $vehicle, DateRange $range): self
    {
        return new self(sprintf(
            'Vehicle [%s] is already held for a window overlapping %s.',
            $vehicle->registration,
            (string) $range,
        ));
    }

    public static function vanished(int|string $vehicleId): self
    {
        return new self("Vehicle [{$vehicleId}] no longer exists.");
    }
}
