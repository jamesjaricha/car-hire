<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InsurancePriceMode;
use App\Models\Operator;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleClass>
 */
final class VehicleClassFactory extends Factory
{
    protected $model = VehicleClass::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Economy', 'Compact', 'Saloon', 'SUV', 'Double Cab 4x4', 'Minibus', 'Luxury',
        ]);

        return [
            'operator_id' => Operator::factory(),
            'name' => $name,
            // Suffixed rather than drawn from a unique() pool: a test that
            // needs more classes than there are names in the list should not
            // fail with a faker overflow.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => $this->faker->sentence(),
            // Money is given as exact 2dp strings so tests can assert on them
            // with assertSame without float comparison creeping in.
            'daily_rate' => '850.00',
            'insurance_price' => '120.00',
            'insurance_price_mode' => InsurancePriceMode::PerDay,
            'insurance_excess_amount' => '5000.00',
            'security_deposit_amount' => '2500.00',
            'turnaround_buffer_minutes' => 120,
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function flatInsurance(): self
    {
        return $this->state(fn (): array => [
            'insurance_price_mode' => InsurancePriceMode::Flat,
        ]);
    }

    public function withBuffer(int $minutes): self
    {
        return $this->state(fn (): array => [
            'turnaround_buffer_minutes' => $minutes,
        ]);
    }
}
