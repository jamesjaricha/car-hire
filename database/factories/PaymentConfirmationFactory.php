<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentConfirmation>
 *
 * Only ever one of these per payment — the database refuses a second. A test
 * that creates two against the same payment is testing the unique key, and
 * should say so.
 */
final class PaymentConfirmationFactory extends Factory
{
    protected $model = PaymentConfirmation::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'confirmed_by_user_id' => User::factory(),
            'branch_id' => null,
            'amount_confirmed' => '2310.00',
            'confirmed_at' => CarbonImmutable::now(),
            'notes' => null,
        ];
    }

    public function forPayment(Payment $payment): self
    {
        return $this->state(fn (): array => [
            'payment_id' => $payment->getKey(),
            'amount_confirmed' => $payment->amount,
        ]);
    }

    public function by(User $user): self
    {
        return $this->state(fn (): array => [
            'confirmed_by_user_id' => $user->getKey(),
            'branch_id' => $user->branch_id,
        ]);
    }
}
