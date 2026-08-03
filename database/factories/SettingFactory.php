<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'value' => $this->faker->word(),
            'type' => 'string',
            'group' => 'general',
            'label' => $this->faker->words(3, true),
            'description' => null,
            'is_placeholder' => false,
        ];
    }
}
