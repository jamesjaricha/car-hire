<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Contracts\AvailabilityServiceContract;
use App\Contracts\PricingServiceContract;
use App\DataTransferObjects\DateRange;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which vehicles are free for a given window.
 *
 * A vehicle is free when it is in the bookable fleet and no hold that still
 * claims it overlaps the requested window once both ends have been padded by
 * the class's turnaround buffer. Padding both ends guarantees at least the
 * buffer's worth of clear time between any two hires, in either direction.
 *
 * Results here are advisory. See the contract for why that distinction matters.
 */
final class AvailabilityService implements AvailabilityServiceContract
{
    public function __construct(
        private readonly PricingServiceContract $pricing,
    ) {}

    public function isVehicleAvailable(Vehicle $vehicle, DateRange $range): bool
    {
        if (! $vehicle->status->isBookable()) {
            return false;
        }

        $padded = $range->paddedBy($this->pricing->turnaroundBufferMinutesFor($vehicle));

        return ! VehicleHold::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->stillClaiming(CarbonImmutable::now())
            ->overlapping($padded->start, $padded->end)
            ->exists();
    }

    /**
     * @return Collection<int, Vehicle>
     */
    public function availableVehicles(
        Branch $branch,
        DateRange $range,
        ?VehicleClass $class = null,
    ): Collection {
        $candidates = Vehicle::query()
            ->with('vehicleClass')
            ->where('branch_id', $branch->getKey())
            ->when($class !== null, fn ($query) => $query->where('vehicle_class_id', $class->getKey()))
            ->bookable()
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $blocked = $this->blockedVehicleIds($candidates, $range);

        return $candidates
            ->reject(fn (Vehicle $vehicle): bool => $blocked->contains($vehicle->getKey()))
            ->values();
    }

    /**
     * IDs of candidate vehicles that already have a conflicting hold.
     *
     * The turnaround buffer is a per-class value, so candidates are grouped by
     * buffer and one query is run per distinct value — typically a handful.
     * This keeps the date arithmetic in PHP rather than in raw SQL, and keeps
     * these comparisons byte-for-byte identical to isVehicleAvailable(), which
     * is what stops the two drifting apart.
     *
     * @param  Collection<int, Vehicle>  $candidates
     * @return Collection<int, int>
     */
    private function blockedVehicleIds(Collection $candidates, DateRange $range): Collection
    {
        $now = CarbonImmutable::now();

        return $candidates
            ->groupBy(fn (Vehicle $vehicle): int => $this->pricing->turnaroundBufferMinutesFor($vehicle))
            ->flatMap(function (Collection $group, int|string $buffer) use ($range, $now): Collection {
                $padded = $range->paddedBy((int) $buffer);

                return VehicleHold::query()
                    ->whereIn('vehicle_id', $group->map(fn (Vehicle $v): int => $v->getKey())->all())
                    ->stillClaiming($now)
                    ->overlapping($padded->start, $padded->end)
                    ->pluck('vehicle_id');
            })
            ->unique()
            ->values();
    }
}
