<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
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
     * Turn a payment hold into a hire claim.
     *
     * A hold is created with `expires_at` set to the PAYMENT DEADLINE, because
     * until the money arrives that is all the claim is worth. Once the booking
     * is confirmed that reason has gone and a different one has taken its
     * place: the car is spoken for until the customer brings it back.
     *
     * Without this the deadline would still lapse, the hold would stop
     * claiming, and the vehicle would return to sale in the middle of a hire
     * that has been paid for. Availability and `place()` both decide from
     * holds, so a hold that stops claiming is a car that has been sold twice.
     *
     * Returns null when the booking has no live hold — a short-notice booking
     * never had one (spec §8.2), and that is not an error.
     */
    public function extendToHireEnd(Booking $booking): ?VehicleHold;

    /**
     * Move a booking's hold out to a new payment deadline.
     *
     * A deadline and the hold that backs it are one fact stored in two places.
     * Extending the first without the second gives the customer more time while
     * releasing their car — the same failure as a confirmed booking losing its
     * vehicle, reached by a different route.
     *
     * Never shortens. A hold already claiming the vehicle for longer keeps its
     * later date, so this can only ever add certainty.
     *
     * Returns null when the booking has no live hold, which for a short-notice
     * booking is normal rather than an error.
     */
    public function extendToDeadline(Booking $booking, CarbonImmutable $deadlineAt): ?VehicleHold;

    /**
     * Release every hold whose payment deadline has passed.
     *
     * Returns the number released. Runs on a schedule, and is also exposed as a
     * manual admin action: if the scheduled run dies unnoticed, vehicles would
     * otherwise stay claimed and inventory would quietly disappear.
     */
    public function releaseExpired(?CarbonImmutable $asOf = null): int;
}
