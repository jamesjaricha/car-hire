<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operational state of a physical vehicle.
 *
 * This is deliberately separate from booking state. A vehicle being on hire
 * does not change its status — that is expressed by holds and bookings
 * covering a date range. Status answers a different question: is this vehicle
 * part of the bookable fleet at all?
 */
enum VehicleStatus: string
{
    /** In the fleet and bookable, subject to holds and bookings. */
    case Available = 'available';

    /** Temporarily out of service — servicing, repair, accident damage. */
    case Maintenance = 'maintenance';

    /** Permanently removed from the fleet. Never bookable again. */
    case Retired = 'retired';

    /**
     * Whether a vehicle in this status may be offered to customers.
     */
    public function isBookable(): bool
    {
        return $this === self::Available;
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Maintenance => 'In maintenance',
            self::Retired => 'Retired',
        };
    }
}
