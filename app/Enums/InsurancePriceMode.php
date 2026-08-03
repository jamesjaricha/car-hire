<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a vehicle class's mandatory damage waiver is priced.
 *
 * Spec §10 leaves the choice open per class, so both modes are supported
 * rather than one being hardcoded.
 */
enum InsurancePriceMode: string
{
    /** Charged for each day of the hire. */
    case PerDay = 'per_day';

    /** Charged once per booking, regardless of duration. */
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::PerDay => 'Per day',
            self::Flat => 'Flat per booking',
        };
    }
}
