<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 *
 * The snapshot columns carry plausible defaults rather than values read from
 * the generated vehicle. Use forVehicle() when a test needs them to agree with
 * the vehicle it created — most do not, and making every booking fixture read
 * back its vehicle would put four extra queries behind every one of them.
 */
final class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $pickupAt = CarbonImmutable::now()->addDays(7)->setTime(9, 0);
        $dropoffAt = $pickupAt->addDays(3);

        return [
            'reference' => 'BR-'.$this->faker->unique()->numerify('#####'),

            'operator_id' => Operator::factory(),

            // Resolved from the operator settled above, so a booking's vehicle,
            // class and branches all belong to one operator.
            'vehicle_class_id' => fn (array $attributes): int => VehicleClass::factory()
                ->create(['operator_id' => $attributes['operator_id']])
                ->getKey(),

            'pickup_branch_id' => fn (array $attributes): int => Branch::factory()
                ->create(['operator_id' => $attributes['operator_id']])
                ->getKey(),

            'dropoff_branch_id' => fn (array $attributes): int => $attributes['pickup_branch_id'],

            'vehicle_id' => fn (array $attributes): int => Vehicle::factory()
                ->create([
                    'operator_id' => $attributes['operator_id'],
                    'vehicle_class_id' => $attributes['vehicle_class_id'],
                    'branch_id' => $attributes['pickup_branch_id'],
                ])
                ->getKey(),

            'customer_id' => Customer::factory(),

            'pickup_at' => $pickupAt,
            'dropoff_at' => $dropoffAt,

            'status' => BookingStatus::PendingPayment,

            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'payment_deadline_at' => CarbonImmutable::now()->addHours(48),
            'payment_reminder_at' => CarbonImmutable::now()->addHours(36),
            'is_short_notice' => false,

            'hire_total' => '1950.00',
            'insurance_total' => '360.00',
            'extras_total' => '0.00',
            'cross_border_total' => '0.00',
            'grand_total' => '2310.00',
            'currency' => 'ZMW',

            'pay_in_full' => false,
            'booking_deposit_amount' => '1155.00',
            'deposit_percentage' => 50,
            'amount_paid' => '0.00',
            'balance_due' => '2310.00',

            'security_deposit_amount' => '1500.00',
            'security_deposit_collected_at' => null,
            'security_deposit_returned_at' => null,
            'security_deposit_returned_amount' => null,

            'insurance_excess_amount' => '4000.00',
            'cross_border_country' => null,

            'terms_version' => '2026-08-01',
            'terms_accepted_at' => CarbonImmutable::now(),

            'vehicle_registration' => 'ABC '.$this->faker->unique()->numerify('####'),
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_class_name' => 'Economy',
            'daily_rate_at_booking' => '650.00',
            'chargeable_days' => 3,

            'confirmed_at' => null,
            'vehicle_released_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    /**
     * Attach to a specific vehicle and make the snapshot agree with it.
     */
    public function forVehicle(Vehicle $vehicle): self
    {
        return $this->state(fn (): array => [
            'operator_id' => $vehicle->operator_id,
            'vehicle_id' => $vehicle->getKey(),
            'vehicle_class_id' => $vehicle->vehicle_class_id,
            'pickup_branch_id' => $vehicle->branch_id,
            'dropoff_branch_id' => $vehicle->branch_id,
            'vehicle_registration' => $vehicle->registration,
            'vehicle_make' => $vehicle->make,
            'vehicle_model' => $vehicle->model,
        ]);
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => CarbonImmutable::now(),
            'amount_paid' => '1155.00',
            'balance_due' => '1155.00',
        ]);
    }

    /** Paid in full, deposit taken — everything a release needs. */
    public function readyForRelease(): self
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => CarbonImmutable::now(),
            'pay_in_full' => true,
            'amount_paid' => '2310.00',
            'balance_due' => '0.00',
            'security_deposit_collected_at' => CarbonImmutable::now(),
        ]);
    }

    public function shortNotice(): self
    {
        return $this->state(fn (): array => [
            'is_short_notice' => true,
            'payment_method_code' => PaymentMethodCode::Cash,
            'payment_deadline_at' => null,
            'payment_reminder_at' => null,
        ]);
    }

    public function crossBorder(string $country = 'ZW'): self
    {
        return $this->state(fn (): array => [
            'cross_border_country' => $country,
            'cross_border_total' => '1800.00',
        ]);
    }
}
