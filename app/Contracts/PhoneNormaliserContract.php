<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\NormalisedPhone;

/**
 * Turns whatever a customer typed into a canonical E.164 number.
 *
 * Spec §1.4 requires `097…`, `26097…` and `+26097…` to reach the same stored
 * value. The guideline adds the failure that actually bites: normalising on
 * write but querying with raw input. The match then silently finds nothing and
 * a duplicate customer is created, every single time, for the rest of the
 * platform's life.
 *
 * So: normalise on write AND before every lookup. There is no code path that
 * queries `phone_e164` with anything this service has not produced.
 */
interface PhoneNormaliserContract
{
    public function normalise(string $input, ?string $defaultRegion = null): NormalisedPhone;

    /**
     * Convenience for lookups. Returns null when the input cannot be trusted
     * for matching, so callers must handle that rather than querying with junk.
     */
    public function toE164ForMatching(string $input, ?string $defaultRegion = null): ?string;
}
