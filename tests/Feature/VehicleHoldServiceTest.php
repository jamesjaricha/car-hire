<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VehicleHoldServiceTest extends TestCase
{
    use RefreshDatabase;

    private VehicleHoldServiceContract $holds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->holds = app(VehicleHoldServiceContract::class);
    }

    public function test_it_places_a_hold_on_a_free_vehicle(): void
    {
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        $hold = $this->holds->place($vehicle, $window, CarbonImmutable::now()->addHours(24));

        $this->assertDatabaseHas('vehicle_holds', [
            'id' => $hold->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'released_at' => null,
            'is_active' => 1,
        ]);
    }

    public function test_it_refuses_a_second_hold_over_the_same_window(): void
    {
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        $this->holds->place($vehicle, $window, CarbonImmutable::now()->addHours(24));

        $this->expectException(VehicleNotAvailableException::class);

        $this->holds->place($vehicle, $window, CarbonImmutable::now()->addHours(24));
    }

    public function test_it_refuses_a_partially_overlapping_window(): void
    {
        // The case a simple duplicate-key constraint would miss entirely.
        $vehicle = $this->bookableVehicle();

        $this->holds->place(
            $vehicle,
            DateRange::of('2026-09-01T09:00:00Z', '2026-09-05T09:00:00Z'),
            CarbonImmutable::now()->addHours(24),
        );

        $this->expectException(VehicleNotAvailableException::class);

        $this->holds->place(
            $vehicle,
            DateRange::of('2026-09-04T09:00:00Z', '2026-09-08T09:00:00Z'),
            CarbonImmutable::now()->addHours(24),
        );
    }

    public function test_it_refuses_a_vehicle_that_is_out_of_service(): void
    {
        $vehicle = Vehicle::factory()->inMaintenance()->create();

        $this->expectException(VehicleNotAvailableException::class);

        $this->holds->place($vehicle, $this->hireWindow(), CarbonImmutable::now()->addHours(24));
    }

    public function test_a_released_hold_frees_the_window_again(): void
    {
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        $first = $this->holds->place($vehicle, $window, CarbonImmutable::now()->addHours(24));
        $this->holds->release($first);

        $second = $this->holds->place($vehicle, $window, CarbonImmutable::now()->addHours(24));

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertDatabaseHas('vehicle_holds', ['id' => $second->getKey(), 'is_active' => 1]);
    }

    public function test_placing_a_hold_retires_this_vehicles_lapsed_holds(): void
    {
        // Self-healing: a stalled expiry job must not permanently remove a
        // vehicle from sale.
        $vehicle = $this->bookableVehicle();
        $window = $this->hireWindow();

        $lapsed = VehicleHold::factory()
            ->forRange($window->start, $window->end)
            ->expired()
            ->create(['vehicle_id' => $vehicle->getKey()]);

        $fresh = $this->holds->place($vehicle, $window, CarbonImmutable::now()->addHours(24));

        $this->assertNotNull($lapsed->fresh()->released_at);
        $this->assertNull($lapsed->fresh()->is_active);
        $this->assertDatabaseHas('vehicle_holds', ['id' => $fresh->getKey(), 'is_active' => 1]);
    }

    public function test_releasing_twice_is_harmless(): void
    {
        $vehicle = $this->bookableVehicle();
        $hold = $this->holds->place($vehicle, $this->hireWindow(), CarbonImmutable::now()->addHours(24));

        $this->holds->release($hold);
        $releasedAt = $hold->fresh()->released_at;

        $this->holds->release($hold->fresh());

        $this->assertTrue($releasedAt->equalTo($hold->fresh()->released_at));
    }

    public function test_the_sweep_releases_only_lapsed_holds(): void
    {
        $lapsedVehicle = $this->bookableVehicle();
        $liveVehicle = $this->bookableVehicle();

        $lapsed = VehicleHold::factory()->expired()->create(['vehicle_id' => $lapsedVehicle->getKey()]);
        $live = VehicleHold::factory()->create(['vehicle_id' => $liveVehicle->getKey()]);

        $released = $this->holds->releaseExpired();

        $this->assertSame(1, $released);
        $this->assertNotNull($lapsed->fresh()->released_at);
        $this->assertNull($live->fresh()->released_at);
    }

    public function test_a_late_evening_zambian_booking_stores_the_correct_utc_instant(): void
    {
        // The guideline's timezone case: booked at 23:30 CAT for a pickup the
        // following morning. 23:30 in Lusaka is 21:30 UTC the same day.
        $vehicle = $this->bookableVehicle();

        $range = DateRange::of(
            CarbonImmutable::parse('2026-09-01 23:30:00', 'Africa/Lusaka'),
            CarbonImmutable::parse('2026-09-02 08:00:00', 'Africa/Lusaka'),
        );

        $hold = $this->holds->place($vehicle, $range, CarbonImmutable::now()->addHours(6));

        $this->assertSame(
            '2026-09-01 21:30:00',
            $hold->fresh()->start_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-09-02 06:00:00',
            $hold->fresh()->end_at->utc()->format('Y-m-d H:i:s'),
        );
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
}
