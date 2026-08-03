<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleHold>
 *
 * For fixtures only. Production code must go through VehicleHoldService::place(),
 * which takes the row lock — this factory deliberately bypasses that so tests can
 * set up pre-existing holds cheaply.
 */
final class VehicleHoldFactory extends Factory
{
    protected $model = VehicleHold::class;

    public function definition(): array
    {
        $start = CarbonImmutable::now()->addDays(3)->setTime(9, 0);

        return [
            'vehicle_id' => Vehicle::factory(),
            'booking_id' => null,
            'start_at' => $start,
            'end_at' => $start->addDays(2),
            'expires_at' => CarbonImmutable::now()->addHours(24),
            'released_at' => null,
            'is_active' => 1,
        ];
    }

    public function forRange(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return $this->state(fn (): array => [
            'start_at' => $start,
            'end_at' => $end,
        ]);
    }

    public function released(): self
    {
        return $this->state(fn (): array => [
            'released_at' => CarbonImmutable::now(),
            'is_active' => null,
        ]);
    }

    /**
     * A hold whose payment deadline has already passed but which no expiry
     * sweep has cleaned up yet.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);
    }
}
