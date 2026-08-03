<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Contracts\PricingServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Vehicle;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
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

            // Step 3 — the overlap check, inside the lock. A check performed
            // before the lock proves nothing at all.
            $padded = $range->paddedBy($this->pricing->turnaroundBufferMinutesFor($locked));

            $conflict = VehicleHold::query()
                ->where('vehicle_id', $locked->getKey())
                ->stillClaiming($now)
                ->overlapping($padded->start, $padded->end)
                ->exists();

            if ($conflict) {
                throw VehicleNotAvailableException::rangeAlreadyHeld($locked, $range);
            }

            // Step 4 — write. The unique index on
            // (vehicle_id, start_at, end_at, is_active) is a second net that
            // catches an identical duplicate even if the lock were somehow
            // bypassed. It does not catch partial overlaps; only the lock does.
            return VehicleHold::create([
                'vehicle_id' => $locked->getKey(),
                'booking_id' => $bookingId,
                'start_at' => $range->start,
                'end_at' => $range->end,
                'expires_at' => $expiresAt,
                'released_at' => null,
                'is_active' => 1,
            ]);
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
