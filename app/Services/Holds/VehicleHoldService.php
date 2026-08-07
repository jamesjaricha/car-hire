<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Contracts\PricingServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Places and releases exclusive claims on vehicles.
 *
 * WHY THIS CLASS IS THE ONLY WRITER
 * ---------------------------------
 * Checking availability and then inserting a hold is a read-modify-write, and
 * read-modify-write without a lock is a race. Two customers hitting checkout on
 * the last Hilux within the same few milliseconds will both see it free and
 * both take it.
 *
 * place() closes that window by taking `lockForUpdate()` on the vehicle row
 * before it checks anything. Every hold attempt for a given vehicle therefore
 * queues at that lock: the second request waits until the first has committed,
 * then re-runs its overlap check and sees the hold that was just written.
 *
 * PostgreSQL could express this as an exclusion constraint over a time range,
 * making it structurally impossible regardless of application code. MySQL has
 * no equivalent, so the guarantee is behavioural and depends on every writer
 * coming through here. That is why there is a concurrency test pinning it, and
 * why the model docblock says not to insert holds anywhere else.
 */
final class VehicleHoldService implements VehicleHoldServiceContract
{
    public function __construct(
        private readonly PricingServiceContract $pricing,
    ) {}

    public function place(
        Vehicle $vehicle,
        DateRange $range,
        CarbonImmutable $expiresAt,
        ?int $bookingId = null,
    ): VehicleHold {
        return DB::transaction(function () use ($vehicle, $range, $expiresAt, $bookingId): VehicleHold {
            // Step 1 — take the lock FIRST. Nothing is read about availability
            // before this line, because anything read before it is stale by the
            // time we act on it.
            $locked = Vehicle::query()
                ->whereKey($vehicle->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Vehicle) {
                throw VehicleNotAvailableException::vanished($vehicle->getKey());
            }

            $locked->setRelation('vehicleClass', $vehicle->relationLoaded('vehicleClass')
                ? $vehicle->vehicleClass
                : $locked->vehicleClass()->first());

            if (! $locked->status->isBookable()) {
                throw VehicleNotAvailableException::notBookable($locked);
            }

            $now = CarbonImmutable::now();

            // Step 2 — retire this vehicle's lapsed holds before looking at
            // overlaps. Without this, a lapsed-but-unswept hold would still
            // occupy the unique index while the availability query considered
            // the vehicle free, and the two would disagree.
            $this->releaseExpiredForVehicle($locked, $now);

            // Step 3 — the overlap check, inside the lock and as a locking read.
            //
            // Correctness here rests on two things, and it is worth knowing
            // which does what, because both were learned the hard way.
            //
            // The VEHICLE ROW LOCK above serialises everyone competing for this
            // vehicle. That is what makes the check meaningful at all.
            //
            // READ COMMITTED (set on the connection in config/database.php)
            // is what makes the check see reality. Under InnoDB's REPEATABLE
            // READ default, this transaction's snapshot was fixed at its first
            // read — long before it reached the vehicle lock, since creating a
            // booking reads payment methods, settings, the vehicle class and
            // the customer first. Waiting on the lock does not refresh that
            // snapshot, so a plain SELECT would have consulted a view of
            // vehicle_holds from before the winner committed, found nothing,
            // and inserted a second hold over the same dates.
            //
            // The lockForUpdate() is then defence in depth: under REPEATABLE
            // READ it would force a fresh read on its own, so the guarantee
            // survives someone changing the isolation level back. Under READ
            // COMMITTED it confines itself to matched rows and does not take
            // the range-wide gap locks that made transactions on unrelated
            // vehicles deadlock against each other.
            //
            // None of this is theoretical. Both failures were caught by
            // BookingConcurrencyTest — the stale read first, where four of five
            // processes were stopped only by the unique index, which would not
            // have saved us had the ranges merely overlapped instead of
            // matching exactly.
            $padded = $range->paddedBy($this->pricing->turnaroundBufferMinutesFor($locked));

            $conflict = VehicleHold::query()
                ->where('vehicle_id', $locked->getKey())
                ->stillClaiming($now)
                ->overlapping($padded->start, $padded->end)
                ->lockForUpdate()
                ->limit(1)
                ->get(['id'])
                ->isNotEmpty();

            if ($conflict) {
                throw VehicleNotAvailableException::rangeAlreadyHeld($locked, $range);
            }

            // Step 4 — write. The unique index on
            // (vehicle_id, start_at, end_at, is_active) is a second net that
            // catches an identical duplicate even if the lock were somehow
            // bypassed. It does not catch partial overlaps; only the lock does.
            //
            // If it does fire, that is a losing racer, not a system fault — so
            // it is translated into the same domain exception the caller would
            // have received from the check above. Letting a raw SQL error escape
            // would surface to a customer as a 500 rather than "that vehicle has
            // just gone, here are others like it".
            try {
                return VehicleHold::create([
                    'vehicle_id' => $locked->getKey(),
                    'booking_id' => $bookingId,
                    'start_at' => $range->start,
                    'end_at' => $range->end,
                    'expires_at' => $expiresAt,
                    'released_at' => null,
                    'is_active' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw VehicleNotAvailableException::rangeAlreadyHeld($locked, $range);
            }
        }, attempts: 3);
    }

    public function release(VehicleHold $hold): void
    {
        if ($hold->isReleased()) {
            return;
        }

        $hold->forceFill([
            'released_at' => CarbonImmutable::now(),
            'is_active' => null,
        ])->save();
    }

    /**
     * A confirmed booking's hold claims its vehicle until the hire ends.
     *
     * WHY THIS EXISTS
     *
     * `expires_at` is set to the payment deadline when the hold is placed,
     * because until money arrives that is the whole extent of the claim. Once
     * the booking is confirmed that reason is spent and a stronger one replaces
     * it — but nothing was moving the date, so the deadline still lapsed and
     * `stillClaiming()` stopped matching. Both `AvailabilityService` and
     * `place()` decide from holds alone, so the vehicle came back onto sale
     * partway through a hire somebody had already paid for.
     *
     * Nothing caught it for two phases because no payment could be confirmed
     * until Phase 3; every booking in the suite sat in `pending_payment`, where
     * the deadline is exactly the right expiry.
     *
     * `place()` remains the only INSERTER of holds — this only moves a date on
     * a row that already exists, and moving it later can never create an
     * overlap that was not already there.
     */
    public function extendToHireEnd(Booking $booking): ?VehicleHold
    {
        $hold = VehicleHold::query()
            ->where('booking_id', $booking->getKey())
            ->whereNull('released_at')
            ->orderByDesc('id')
            ->first();

        if (! $hold instanceof VehicleHold) {
            // Short-notice bookings never place one. Spec §8.2.
            return null;
        }

        // Never shorten. A hire ending before the payment deadline — possible
        // on a very late booking — must keep the later of the two, or
        // confirming would hand the car back to the search results.
        if ($hold->expires_at->greaterThanOrEqualTo($hold->end_at)) {
            return $hold;
        }

        $hold->forceFill(['expires_at' => $hold->end_at])->save();

        return $hold;
    }

    public function releaseExpired(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        return VehicleHold::query()
            ->whereNull('released_at')
            ->where('expires_at', '<=', $asOf)
            ->update([
                'released_at' => $asOf,
                'is_active' => null,
            ]);
    }

    /**
     * Release lapsed holds for one vehicle. Called inside the lock, so it is
     * safe against concurrent writers for that vehicle.
     */
    private function releaseExpiredForVehicle(Vehicle $vehicle, CarbonImmutable $asOf): void
    {
        VehicleHold::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereNull('released_at')
            ->where('expires_at', '<=', $asOf)
            ->update([
                'released_at' => $asOf,
                'is_active' => null,
            ]);
    }
}
