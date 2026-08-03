<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PricingServiceContract;
use App\DataTransferObjects\DateRange;
use App\Enums\InsurancePriceMode;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingServiceContract $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = app(PricingServiceContract::class);
    }

    public function test_a_vehicle_without_an_override_inherits_the_class_rate(): void
    {
        $vehicle = $this->vehicleInClass(['daily_rate' => '850.00']);

        $this->assertSame('850.00', $this->pricing->dailyRateFor($vehicle));
    }

    public function test_a_vehicle_override_beats_the_class_rate(): void
    {
        $class = VehicleClass::factory()->create(['daily_rate' => '850.00']);

        $vehicle = Vehicle::factory()->withRateOverride('1200.00')->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
        ]);

        $this->assertSame('1200.00', $this->pricing->dailyRateFor($vehicle));
    }

    public function test_the_security_deposit_falls_back_to_the_class(): void
    {
        $vehicle = $this->vehicleInClass(['security_deposit_amount' => '2500.00']);

        $this->assertSame('2500.00', $this->pricing->securityDepositFor($vehicle));
    }

    public function test_the_security_deposit_can_be_overridden_per_vehicle(): void
    {
        $class = VehicleClass::factory()->create(['security_deposit_amount' => '2500.00']);

        $vehicle = Vehicle::factory()->withDepositOverride('6000.00')->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
        ]);

        $this->assertSame('6000.00', $this->pricing->securityDepositFor($vehicle));
    }

    public function test_the_hire_total_is_the_rate_times_chargeable_days(): void
    {
        $vehicle = $this->vehicleInClass(['daily_rate' => '650.00']);

        // 09:00 Tuesday to 09:00 Friday: three whole days.
        $range = DateRange::of('2026-09-01T09:00:00Z', '2026-09-04T09:00:00Z');

        // Asserted as an exact string, not a float comparison.
        $this->assertSame('1950.00', $this->pricing->hireTotal($vehicle, $range));
    }

    public function test_a_part_day_is_charged_as_a_whole_day(): void
    {
        $vehicle = $this->vehicleInClass(['daily_rate' => '650.00']);

        $range = DateRange::of('2026-09-01T09:00:00Z', '2026-09-02T10:00:00Z');

        $this->assertSame('1300.00', $this->pricing->hireTotal($vehicle, $range));
    }

    public function test_per_day_insurance_scales_with_the_hire_length(): void
    {
        $vehicle = $this->vehicleInClass([
            'insurance_price' => '120.00',
            'insurance_price_mode' => InsurancePriceMode::PerDay,
        ]);

        $range = DateRange::of('2026-09-01T09:00:00Z', '2026-09-04T09:00:00Z');

        $this->assertSame('360.00', $this->pricing->insuranceTotal($vehicle, $range));
    }

    public function test_flat_insurance_is_charged_once_regardless_of_length(): void
    {
        $vehicle = $this->vehicleInClass([
            'insurance_price' => '120.00',
            'insurance_price_mode' => InsurancePriceMode::Flat,
        ]);

        $shortHire = DateRange::of('2026-09-01T09:00:00Z', '2026-09-02T09:00:00Z');
        $longHire = DateRange::of('2026-09-01T09:00:00Z', '2026-09-14T09:00:00Z');

        $this->assertSame('120.00', $this->pricing->insuranceTotal($vehicle, $shortHire));
        $this->assertSame('120.00', $this->pricing->insuranceTotal($vehicle, $longHire));
    }

    public function test_the_turnaround_buffer_comes_from_the_class(): void
    {
        $vehicle = $this->vehicleInClass(['turnaround_buffer_minutes' => 240]);

        $this->assertSame(240, $this->pricing->turnaroundBufferMinutesFor($vehicle));
    }

    public function test_a_class_with_no_buffer_falls_back_to_configuration(): void
    {
        $vehicle = $this->vehicleInClass(['turnaround_buffer_minutes' => 0]);

        $this->assertSame(120, $this->pricing->turnaroundBufferMinutesFor($vehicle));
    }

    /**
     * A vehicle with no overrides, belonging to a class with the given attributes.
     *
     * @param  array<string, mixed>  $classAttributes
     */
    private function vehicleInClass(array $classAttributes): Vehicle
    {
        $class = VehicleClass::factory()->create($classAttributes);

        return Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'daily_rate' => null,
            'security_deposit_amount' => null,
        ]);
    }
}
