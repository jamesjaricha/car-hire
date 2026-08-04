<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Branch;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Raised when a checkout submission cannot become a booking, for reasons other
 * than the vehicle being taken or the payment method being unavailable — those
 * have their own exceptions, because callers handle them differently: a taken
 * vehicle means offering alternatives, whereas these mean the request itself
 * was malformed.
 */
final class BookingNotPossibleException extends DomainException
{
    public static function pickupInThePast(CarbonImmutable $pickupAt, CarbonImmutable $now): self
    {
        return new self(sprintf(
            'Pickup is set for %s, which has already passed (it is now %s).',
            $pickupAt->toIso8601String(),
            $now->toIso8601String(),
        ));
    }

    public static function branchBelongsToAnotherOperator(Branch $branch, Vehicle $vehicle): self
    {
        return new self(sprintf(
            'Branch [%s] does not belong to the operator that owns vehicle [%s].',
            $branch->code,
            $vehicle->registration,
        ));
    }
}
