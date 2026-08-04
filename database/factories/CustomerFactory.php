<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        // Zambian mobile in the canonical stored form. Set explicitly rather
        // than generated loosely, so tests can match on it exactly.
        // Zambian mobile national numbers are nine digits: 9, network digit,
        // then seven. e.g. 977123456 → +260977123456.
        $national = '9'.$this->faker->numerify('7#######');

        return [
            'full_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone_e164' => '+260'.$national,
            'phone_raw' => '0'.$national,
            'phone_region' => 'ZM',
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'password' => null,
            'needs_staff_review' => false,
            'review_reason' => null,
            'possible_duplicate_of_customer_id' => null,
        ];
    }

    /** A customer who accepted the invitation and set a password. */
    public function withAccount(): self
    {
        return $this->state(fn (): array => [
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
    }

    public function flaggedForReview(string $reason = 'Conflicting details at checkout.'): self
    {
        return $this->state(fn (): array => [
            'needs_staff_review' => true,
            'review_reason' => $reason,
        ]);
    }
}
