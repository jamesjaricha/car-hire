<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AvailabilityServiceContract;
use App\DataTransferObjects\DateRange;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityServiceContract $availability;

    protected function setUp(): void
    {
        parent::setUp();

        // Availability is time-relative — a hold "still claiming" depends on
        // now. Freezing removes the flake, and stops a test behaving
        // differently depending on the hour it runs at.
        $this->freezeTime();

        $this->availability = app(AvailabilityServiceContract::class);
    }

    public function test_a_vehicle_with_no_holds_is_available(): void
    {
        $vehicle = $this->bookableVehicle();

        $this->assertTrue($this->availability->isVehicleAvailable($vehicle, $this->hireWindow()));
    }

    public function test_an_overlapping_hold_makes_a_vehicle_unavailable(): void
    {
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        $this->holdFor($vehicle, $window->start->addHours(6), $window->end->addHours(6));

        $this->assertFalse($this->availability->isVehicleAvailable($vehicle, $window));
    }

    public function test_a_released_hold_does_not_block(): void
    {
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        VehicleHold::factory()
            ->forRange($window->start, $window->end)
            ->released()
            ->create(['vehicle_id' => $vehicle->getKey()]);

        $this->assertTrue($this->availability->isVehicleAvailable($vehicle, $window));
    }

    public function test_a_lapsed_hold_does_not_block(): void
    {
        // A hold whose payment deadline has passed but which no sweep has
        // cleaned up. Inventory must not vanish because a scheduled job died.
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        VehicleHold::factory()
            ->forRange($window->start, $window->end)
            ->expired()
            ->create(['vehicle_id' => $vehicle->getKey()]);

        $this->assertTrue($this->availability->isVehicleAvailable($vehicle, $window));
    }

    public function test_a_vehicle_in_maintenance_is_never_available(): void
    {
        $vehicle = Vehicle::factory()->inMaintenance()->create();

        $this->assertFalse($this->availability->isVehicleAvailable($vehicle, $this->hireWindow()));
    }

    public function test_a_retired_vehicle_is_never_available(): void
    {
        $vehicle = Vehicle::factory()->retired()->create();

        $this->assertFalse($this->availability->isVehicleAvailable($vehicle, $this->hireWindow()));
    }

    public function test_the_turnaround_buffer_blocks_a_hire_starting_too_soon_after_one_ending(): void
    {
        $vehicle = $this->bookableVehicle(bufferMinutes: 120);

        $previousEnd = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $this->holdFor($vehicle, $previousEnd->subDays(2), $previousEnd);

        // 11:00 is only one hour after the vehicle came back. Not enough.
        $tooSoon = DateRange::of($previousEnd->addHour(), $previousEnd->addDays(2));

        $this->assertFalse($this->availability->isVehicleAvailable($vehicle, $tooSoon));
    }

    public function test_a_hire_starting_exactly_one_buffer_later_is_allowed(): void
    {
        $vehicle = $this->bookableVehicle(bufferMinutes: 120);

        $previousEnd = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $this->holdFor($vehicle, $previousEnd->subDays(2), $previousEnd);

        // Exactly 12:00 — the full two hours have elapsed, so this is fine.
        $exactlyEnough = DateRange::of($previousEnd->addHours(2), $previousEnd->addDays(2));

        $this->assertTrue($this->availability->isVehicleAvailable($vehicle, $exactlyEnough));
    }

    public function test_the_buffer_also_applies_before_an_existing_hold(): void
    {
        $vehicle = $this->bookableVehicle(bufferMinutes: 120);

        $nextStart = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $this->holdFor($vehicle, $nextStart, $nextStart->addDays(2));

        // Returning at 09:00 leaves only an hour before the next hire departs.
        $endsTooLate = DateRange::of($nextStart->subDays(2), $nextStart->subHour());

        $this->assertFalse($this->availability->isVehicleAvailable($vehicle, $endsTooLate));
    }

    public function test_a_longer_class_buffer_is_honoured(): void
    {
        $vehicle = $this->bookableVehicle(bufferMinutes: 240);

        $previousEnd = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $this->holdFor($vehicle, $previousEnd->subDays(2), $previousEnd);

        // Fine for a two-hour class, not for a four-hour one.
        $window = DateRange::of($previousEnd->addHours(3), $previousEnd->addDays(2));

        $this->assertFalse($this->availability->isVehicleAvailable($vehicle, $window));
    }

    public function test_the_search_lists_only_bookable_vehicles_at_the_branch(): void
    {
        $branch = Branch::factory()->create();
        $class = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);

        $free = $this->vehicleAt($branch, $class);
        $maintenance = Vehicle::factory()->inMaintenance()->create([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);
        $elsewhere = $this->bookableVehicle();

        $found = $this->availability->availableVehicles($branch, $this->hireWindow());

        $this->assertTrue($found->contains($free));
        $this->assertFalse($found->contains($maintenance));
        $this->assertFalse($found->contains($elsewhere));
    }

    public function test_the_search_can_be_narrowed_to_one_class(): void
    {
        $branch = Branch::factory()->create();
        $wanted = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);
        $other = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);

        $match = $this->vehicleAt($branch, $wanted);
        $mismatch = $this->vehicleAt($branch, $other);

        $found = $this->availability->availableVehicles($branch, $this->hireWindow(), $wanted);

        $this->assertTrue($found->contains($match));
        $this->assertFalse($found->contains($mismatch));
    }

    public function test_the_search_excludes_a_vehicle_that_is_already_held(): void
    {
        $branch = Branch::factory()->create();
        $class = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);

        $free = $this->vehicleAt($branch, $class);
        $held = $this->vehicleAt($branch, $class);

        $window = $this->hireWindow();
        $this->holdFor($held, $window->start, $window->end);

        $found = $this->availability->availableVehicles($branch, $window);

        $this->assertTrue($found->contains($free));
        $this->assertFalse($found->contains($held));
    }

    public function test_the_search_and_the_single_vehicle_check_agree(): void
    {
        // These two use different queries. If they ever disagree, a customer is
        // shown a vehicle that cannot actually be held — or the reverse.
        $branch = Branch::factory()->create();
        $shortBuffer = VehicleClass::factory()->create([
            'operator_id' => $branch->operator_id,
            'turnaround_buffer_minutes' => 60,
        ]);
        $longBuffer = VehicleClass::factory()->create([
            'operator_id' => $branch->operator_id,
            'turnaround_buffer_minutes' => 300,
        ]);

        $quick = $this->vehicleAt($branch, $shortBuffer);
        $slow = $this->vehicleAt($branch, $longBuffer);

        $previousEnd = CarbonImmutable::parse('2026-09-10T10:00:00Z');
        $this->holdFor($quick, $previousEnd->subDays(2), $previousEnd);
        $this->holdFor($slow, $previousEnd->subDays(2), $previousEnd);

        // Two hours later: long enough for the 60-minute class, not the 300.
        $window = DateRange::of($previousEnd->addHours(2), $previousEnd->addDays(2));

        $found = $this->availability->availableVehicles($branch, $window);

        $this->assertSame(
            $this->availability->isVehicleAvailable($quick->fresh(), $window),
            $found->contains($quick),
        );
        $this->assertSame(
            $this->availability->isVehicleAvailable($slow->fresh(), $window),
            $found->contains($slow),
        );

        $this->assertTrue($found->contains($quick));
        $this->assertFalse($found->contains($slow));
    }

    private function hireWindow(): DateRange
    {
        return DateRange::of('2026-09-01T09:00:00Z', '2026-09-04T09:00:00Z');
    }

    private function bookableVehicle(int $bufferMinutes = 120): Vehicle
    {
        $class = VehicleClass::factory()->create(['turnaround_buffer_minutes' => $bufferMinutes]);

        return Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
        ]);
    }

    private function vehicleAt(Branch $branch, VehicleClass $class): Vehicle
    {
        return Vehicle::factory()->create([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);
    }

    private function holdFor(Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): VehicleHold
    {
        return VehicleHold::factory()->forRange($start, $end)->create([
            'vehicle_id' => $vehicle->getKey(),
        ]);
    }
}
