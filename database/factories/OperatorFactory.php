<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Operator>
 */
final class OperatorFactory extends Factory
{
    protected $model = Operator::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            // Set explicitly rather than derived by a model hook: seeders that
            // suppress model events would otherwise leave this null.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'contact_email' => $this->faker->companyEmail(),
            'contact_phone_e164' => '+2609'.$this->faker->numerify('7#######'),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
