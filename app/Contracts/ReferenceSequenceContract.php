<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A gapless, concurrency-safe counter, keyed by prefix.
 *
 * Extracted from BookingReferenceGenerator when payments needed the same
 * mechanism. The locking is the tricky part, and having two copies of it is how
 * one of them quietly stops matching the other.
 */
interface ReferenceSequenceContract
{
    /**
     * The next value for this prefix, reserved.
     *
     * Gapless: the value is consumed whether or not the caller goes on to use
     * it, unless the surrounding transaction rolls back. Callers that may
     * abandon the value should be inside a transaction that will.
     */
    public function next(string $prefix): int;
}
