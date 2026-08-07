<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\ExpirySweepResult;
use Carbon\CarbonImmutable;

/**
 * Cancels bookings whose payment deadline has passed. Spec §8.4.
 *
 * "On deadline lapse: booking → cancelled_non_payment, payment →
 * payment_expired, hold released immediately, customer notified."
 *
 * Runs on a schedule and is also invocable by hand. The guideline warns that a
 * dead expiry job locks vehicles out of sale and that the failure is silent, so
 * a manual trigger is a requirement rather than a convenience.
 */
interface BookingExpiryServiceContract
{
    /**
     * Sweep every booking whose deadline has passed.
     *
     * Safe to run concurrently with itself and with staff confirming payments:
     * each booking is taken under its own lock and re-checked there, so a
     * confirmation landing a second before the sweep wins rather than being
     * overwritten by it.
     */
    public function sweep(?CarbonImmutable $asOf = null): ExpirySweepResult;
}
