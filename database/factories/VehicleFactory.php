<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VehicleStatus;
use App\Models\Branch;
use App\Models\Operator;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
final class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'operator_id' => Operator::factory(),

            // These resolve from the operator_id already settled above, so a
            // vehicle, its class and its branch always belong to the same
            // operator. Passing Operator::factory() to each of them instead
            // would quietly create three unrelated operators.
            'vehicle_class_id' => fn (array $attributes): int => VehicleClass::factory()
                ->create(['operator_id' => $attributes['operator_id']])
                ->getKey(),

            'branch_id' => fn (array $attributes): int => Branch::factory()
                ->create(['operator_id' => $attributes['operator_id']])
                ->getKey(),

            'registration' => 'A'.$this->faker->unique()->numerify('BC ####'),
            'make' => $this->faker->randomElement(['Toyota', 'Nissan', 'Mazda', 'Mitsubishi']),
            'model' => $this->faker->randomElement(['Corolla', 'Hilux', 'X-Trail', 'Demio']),
            'year' => $this->faker->numberBetween(2016, 2026),
            'colour' => $this->faker->safeColorName(),
            'transmission' => $this->faker->randomElement(['manual', 'automatic']),
            'fuel_type' => $this->faker->randomElement(['petrol', 'diesel']),
            'seats' => 5,
            // Null means "inherit from the class", which is the normal case.
            'daily_rate' => null,
            'security_deposit_amount' => null,
            'status' => VehicleStatus::Available,
            'notes' => null,
        ];
    }

    public function inMaintenance(): self
    {
        return $this->state(fn (): array => ['status' => VehicleStatus::Maintenance]);
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['status' => VehicleStatus::Retired]);
    }

    /**
     * Give this vehicle its own rate, overriding the class.
     */
    public function withRateOverride(string $dailyRate): self
    {
        return $this->state(fn (): array => ['daily_rate' => $dailyRate]);
    }

    public function withDepositOverride(string $amount): self
    {
        return $this->state(fn (): array => ['security_deposit_amount' => $amount]);
    }
}
