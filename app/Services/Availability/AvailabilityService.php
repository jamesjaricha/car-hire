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

        if (! $this->isSellable($vehicle)) {
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

        // Withheld before anything else is considered. A class whose security
        // deposit or excess nobody has decided cannot be shown to a customer:
        // spec §6 and §10 both require those figures in the search results, and
        // an undecided one would render as zero. This is the protection; the
        // exception in PricingService is only the backstop.
        $candidates = $candidates
            ->filter(fn (Vehicle $vehicle): bool => $this->isSellable($vehicle))
            ->values();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $blocked = $this->blockedVehicleIds($candidates, $range);

        return $candidates
            ->reject(fn (Vehicle $vehicle): bool => $blocked->contains($vehicle->getKey()))
            ->values();
    }

    /**
     * Whether this vehicle may lawfully be offered to a customer.
     *
     * A vehicle carrying its own `security_deposit_amount` override is sellable
     * even while its class has not decided one — the deposit shown would be the
     * vehicle's. The excess has no vehicle-level override, so an undecided
     * excess withholds every vehicle in the class.
     *
     * A vehicle whose class row is missing entirely is not sellable either.
     * That is a data fault rather than a pricing decision, but the answer to
     * "can a customer be shown this" is the same, and it is not this service's
     * job to decide which kind of broken it is.
     */
    private function isSellable(Vehicle $vehicle): bool
    {
        if (! $vehicle->relationLoaded('vehicleClass')) {
            $vehicle->setRelation('vehicleClass', $vehicle->vehicleClass()->first());
        }

        $class = $vehicle->vehicleClass;

        if (! $class instanceof VehicleClass) {
            return false;
        }

        if ($class->isFullyPriced()) {
            return true;
        }

        return $vehicle->security_deposit_amount !== null
            && $class->insurance_price !== null
            && $class->insurance_excess_amount !== null;
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
