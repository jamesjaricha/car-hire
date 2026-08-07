<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 *
 * The default is a receipt as the checkout raises it: money expected, nothing
 * arrived, nobody has confirmed anything. States move it forward from there.
 *
 * Note that `amount` defaults to zero rather than to the expected figure. A
 * fixture that arrives pre-paid makes it far too easy to write a confirmation
 * test that passes without any money having been recorded.
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),

            // Taken from the booking rather than generated, so a payment and
            // the booking it belongs to never sit under different operators.
            'operator_id' => fn (array $attributes): ?int => $attributes['booking_id'] === null
                ? null
                : Booking::query()->whereKey($attributes['booking_id'])->value('operator_id'),

            'payment_reference' => 'BR-'.$this->faker->unique()->numerify('#####').'-1',

            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'status' => PaymentStatus::AwaitingPayment,

            'is_deposit' => false,

            'amount' => '0.00',
            'expected_amount' => '2310.00',
            'currency' => 'ZMW',

            'external_reference' => null,
            'notes' => null,

            'proof_path' => null,
            'proof_uploaded_at' => null,

            'recorded_by_user_id' => null,
            'matched_by_user_id' => null,
            'matched_at' => null,
        ];
    }

    public function forBooking(Booking $booking): self
    {
        return $this->state(fn (): array => [
            'booking_id' => $booking->getKey(),
            'operator_id' => $booking->operator_id,
        ]);
    }

    /**
     * The 50% part-payment of the hire, not the refundable security deposit.
     */
    public function deposit(string $expected = '1155.00'): self
    {
        return $this->state(fn (): array => [
            'is_deposit' => true,
            'expected_amount' => $expected,
        ]);
    }

    /**
     * Money has arrived and a staff member has verified it.
     *
     * This sets the status directly, which nothing outside a fixture may do —
     * real confirmation goes through PaymentConfirmationService so that the
     * unique key on payment_confirmations is what refuses a duplicate.
     */
    public function confirmed(?string $amount = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Confirmed,
            'amount' => $amount ?? $attributes['expected_amount'] ?? '0.00',
        ]);
    }

    /**
     * The customer has uploaded something. It is not confirmation.
     */
    public function proofSubmitted(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::ProofSubmitted,
            'proof_path' => 'proofs/'.$this->faker->uuid().'.jpg',
            'proof_uploaded_at' => CarbonImmutable::now(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::PaymentExpired,
        ]);
    }

    /**
     * Money that arrived without anyone knowing whose booking it belongs to.
     *
     * No booking, no operator and no expectation — the guideline's unmatched
     * payments queue. The amount is real, because the money is real; it is the
     * attribution that is missing.
     */
    public function unmatched(string $amount = '1155.00'): self
    {
        return $this->state(fn (): array => [
            'booking_id' => null,
            'operator_id' => null,
            'expected_amount' => null,
            'amount' => $amount,
            'external_reference' => 'MP'.$this->faker->unique()->numerify('##########'),
            'recorded_by_user_id' => User::factory(),
        ]);
    }
}
