<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Operator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        $city = $this->faker->randomElement(['Lusaka', 'Livingstone', 'Ndola', 'Kitwe']);

        return [
            'operator_id' => Operator::factory(),
            'name' => $city.' Branch',
            'code' => Str::upper(Str::substr($city, 0, 3)).$this->faker->unique()->numerify('##'),
            'city' => $city,
            'address' => $this->faker->streetAddress(),
            'phone_e164' => '+2609'.$this->faker->numerify('7#######'),
            'opens_at' => '08:00:00',
            'closes_at' => '17:00:00',
            'after_hours_pickup' => false,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
