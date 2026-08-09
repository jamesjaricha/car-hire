<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\VehicleClass;
use DomainException;

/**
 * A vehicle class is missing a figure only the business can decide.
 *
 * Raised rather than defaulted, and that is the whole point. Before the
 * 2026-08-09 migration these columns defaulted to zero, so an undecided
 * security deposit priced as "no deposit required" and an undecided excess as
 * "you are liable for nothing" — both shown to the customer, in writing, from
 * the search results onward.
 *
 * Spec §6 says the security deposit must never first appear at the counter.
 * A silent zero is precisely how it would: nothing on the website mentions it,
 * and then somebody is asked for K2,500 as they collect the keys.
 *
 * So the failure is loud, and it happens where it can still be fixed. In
 * practice a customer should never see this at all — `AvailabilityService`
 * keeps an unpriced class out of search results, so the exception is a
 * backstop for code paths that reach a class another way.
 */
final class VehicleClassNotPricedException extends DomainException
{
    public static function missingDecisions(VehicleClass $class): self
    {
        return new self(sprintf(
            'Vehicle class [%s] cannot be priced: %s %s not been decided. '
            .'Set them in the admin panel under Fleet; until then this class is withheld from search '
            .'results, because spec §6 and §10 require both figures to be shown to the customer before '
            .'they pay.',
            $class->name,
            implode('; ', $class->missingPricingDecisions()),
            count($class->missingPricingDecisions()) === 1 ? 'has' : 'have',
        ));
    }
}
