<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BookingCreationServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\BookingRequest;
use App\DataTransferObjects\CustomerDetails;
use App\DataTransferObjects\DateRange;
use App\DataTransferObjects\QuoteOptions;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\CustomerResolutionOutcome;
use App\Enums\InsurancePriceMode;
use App\Enums\PaymentMethodCode;
use App\Exceptions\BookingNotPossibleException;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingCreationServiceContract $bookings;

    private CarbonImmutable $now;

    private Vehicle $vehicle;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(PaymentMethodSeeder::class);

        $class = VehicleClass::factory()->create([
            'daily_rate' => '650.00',
            'insurance_price' => '120.00',
            'insurance_price_mode' => InsurancePriceMode::PerDay,
            'insurance_excess_amount' => '4000.00',
            'security_deposit_amount' => '1500.00',
            'name' => 'Economy',
        ]);

        $this->branch = Branch::factory()->create(['operator_id' => $class->operator_id]);

        $this->vehicle = Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $this->branch->getKey(),
            'registration' => 'ABC 4242',
            'make' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->bookings = app(BookingCreationServiceContract::class);
    }

    public function test_a_booking_is_created_awaiting_payment(): void
    {
        $result = $this->bookings->create($this->request());

        $booking = $result->booking;

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame('BR-00001', $booking->reference);
        $this->assertSame(PaymentMethodCode::BankTransfer, $booking->payment_method_code);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_the_quoted_price_is_what_lands_on_the_booking(): void
    {
        // Spec §14.4: the price in search results equals the price charged.
        $result = $this->bookings->create($this->request());

        $this->assertSame('1950.00', $result->booking->hire_total);
        $this->assertSame('360.00', $result->booking->insurance_total);
        $this->assertSame('2310.00', $result->booking->grand_total);
        $this->assertSame($result->quote->grandTotal, $result->booking->grand_total);
    }

    public function test_nothing_is_paid_at_the_moment_of_booking(): void
    {
        // Whichever option the customer chose, no money has arrived until a
        // staff member confirms it. A booking that thought itself part-paid
        // could be released without anyone having checked a statement.
        $result = $this->bookings->create($this->request(payInFull: false));

        $this->assertSame('0.00', $result->booking->amount_paid);
        $this->assertSame('2310.00', $result->booking->balance_due);

        // The deposit is recorded as what they were told to send.
        $this->assertSame('1155.00', $result->booking->booking_deposit_amount);
        $this->assertSame('1155.00', $result->amountDueNow());
    }

    /**
     * Read off the model the service returns, not from a re-query.
     *
     * `create()` does not read column defaults back, so a value left to the
     * database default is absent from the returned instance — null in
     * production, and a MissingAttributeException under strict mode. Phase 2
     * shipped exactly that fault once already, in CustomerResolver.
     */
    public function test_the_returned_booking_carries_its_payment_status(): void
    {
        $result = $this->bookings->create($this->request());

        $this->assertSame(
            BookingPaymentStatus::AwaitingPayment,
            $result->booking->payment_status,
        );

        $this->assertDatabaseHas('bookings', [
            'id' => $result->booking->getKey(),
            'payment_status' => 'awaiting_payment',
        ]);
    }

    public function test_paying_in_full_changes_only_what_is_due_now(): void
    {
        $result = $this->bookings->create($this->request(payInFull: true));

        $this->assertTrue($result->booking->pay_in_full);
        $this->assertSame('2310.00', $result->amountDueNow());
        $this->assertSame('2310.00', $result->booking->balance_due);
    }

    public function test_the_vehicle_is_held_and_the_hold_knows_its_booking(): void
    {
        $result = $this->bookings->create($this->request());

        $this->assertTrue($result->vehicleIsHeld());
        $this->assertSame($result->booking->getKey(), $result->hold->booking_id);
        $this->assertSame($this->vehicle->getKey(), $result->hold->vehicle_id);
        $this->assertNull($result->hold->released_at);
    }

    public function test_the_deadline_follows_the_payment_method(): void
    {
        // Pickup in seven days, bank transfer: the 48-hour window applies.
        $result = $this->bookings->create($this->request());

        $this->assertFalse($result->booking->is_short_notice);
        $this->assertTrue($result->booking->payment_deadline_at->equalTo($this->now->addHours(48)));
        $this->assertTrue($result->hold->expires_at->equalTo($this->now->addHours(48)));
    }

    public function test_the_snapshot_records_what_was_agreed(): void
    {
        $result = $this->bookings->create($this->request());

        $this->assertSame('ABC 4242', $result->booking->vehicle_registration);
        $this->assertSame('Toyota', $result->booking->vehicle_make);
        $this->assertSame('Corolla', $result->booking->vehicle_model);
        $this->assertSame('Economy', $result->booking->vehicle_class_name);
        $this->assertSame('650.00', $result->booking->daily_rate_at_booking);
        $this->assertSame(3, $result->booking->chargeable_days);
    }

    public function test_the_snapshot_survives_a_later_price_rise(): void
    {
        $result = $this->bookings->create($this->request());

        VehicleClass::query()
            ->whereKey($this->vehicle->vehicle_class_id)
            ->update(['daily_rate' => '2000.00']);

        // What the customer agreed to is unchanged. Without the snapshot, a
        // dispute six weeks later would be argued against today's price list.
        $this->assertSame('650.00', $result->booking->fresh()->daily_rate_at_booking);
        $this->assertSame('2310.00', $result->booking->fresh()->grand_total);
    }

    public function test_the_terms_accepted_are_recorded_with_a_timestamp(): void
    {
        $result = $this->bookings->create($this->request());

        $this->assertSame('2026-08-01', $result->booking->terms_version);
        $this->assertTrue($result->booking->terms_accepted_at->equalTo($this->now));
    }

    public function test_a_new_customer_is_created_from_the_checkout_details(): void
    {
        $result = $this->bookings->create($this->request());

        $this->assertSame(CustomerResolutionOutcome::Created, $result->customerResolution->outcome);
        $this->assertSame(
            $result->customerResolution->customer->getKey(),
            $result->booking->customer_id,
        );
        $this->assertSame('+260977123456', $result->customerResolution->customer->phone_e164);
    }

    public function test_a_verified_customer_is_linked_rather_than_duplicated(): void
    {
        $existing = Customer::factory()->withAccount()->create(['email' => 'known@example.com']);

        $result = $this->bookings->create($this->request(verifiedCustomer: $existing));

        $this->assertSame($existing->getKey(), $result->booking->customer_id);
        $this->assertDatabaseCount('customers', 1);
    }

    // --- Short notice ----------------------------------------------------

    public function test_a_short_notice_booking_holds_no_vehicle(): void
    {
        // Spec §8.2: within four hours of pickup, availability is first-come at
        // the counter. Holding a vehicle here would take it off sale against
        // the specification.
        $result = $this->bookings->create($this->request(
            range: DateRange::of($this->now->addHours(3), $this->now->addDays(3)),
            paymentMethodCode: 'cash',
        ));

        $this->assertTrue($result->booking->is_short_notice);
        $this->assertNull($result->booking->payment_deadline_at);
        $this->assertNull($result->booking->payment_reminder_at);
        $this->assertFalse($result->vehicleIsHeld());
        $this->assertDatabaseCount('vehicle_holds', 0);
    }

    public function test_a_remote_method_is_refused_within_the_short_notice_window(): void
    {
        $this->expectException(PaymentMethodNotAvailableException::class);

        $this->bookings->create($this->request(
            range: DateRange::of($this->now->addHours(3), $this->now->addDays(3)),
            paymentMethodCode: 'bank_transfer',
        ));
    }

    // --- Refusals, and what they leave behind ----------------------------

    public function test_a_disabled_payment_method_is_refused(): void
    {
        $this->expectException(PaymentMethodNotAvailableException::class);

        $this->bookings->create($this->request(paymentMethodCode: 'debit_card'));
    }

    public function test_an_already_held_vehicle_is_refused(): void
    {
        // The case the whole design exists for: someone took this vehicle while
        // the customer was filling in the checkout form.
        app(VehicleHoldServiceContract::class)->place(
            $this->vehicle,
            $this->hireWindow(),
            $this->now->addHours(24),
        );

        $this->expectException(VehicleNotAvailableException::class);

        $this->bookings->create($this->request());
    }

    public function test_a_pickup_in_the_past_is_refused(): void
    {
        $this->expectException(BookingNotPossibleException::class);

        $this->bookings->create($this->request(
            range: DateRange::of($this->now->subDay(), $this->now->addDay()),
        ));
    }

    public function test_a_branch_from_another_operator_is_refused(): void
    {
        $foreignBranch = Branch::factory()->create();

        $this->expectException(BookingNotPossibleException::class);

        $this->bookings->create($this->request(pickupBranch: $foreignBranch));
    }

    public function test_a_failed_booking_leaves_nothing_behind(): void
    {
        // Atomicity. A half-completed checkout that left a stranded hold would
        // quietly remove a vehicle from sale for the length of the hire.
        app(VehicleHoldServiceContract::class)->place(
            $this->vehicle,
            $this->hireWindow(),
            $this->now->addHours(24),
        );

        $customersBefore = Customer::query()->count();
        $holdsBefore = VehicleHold::query()->count();

        try {
            $this->bookings->create($this->request());
        } catch (VehicleNotAvailableException) {
            // Expected.
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame($customersBefore, Customer::query()->count());
        $this->assertSame($holdsBefore, VehicleHold::query()->count());

        // And the reference was not burned.
        $this->assertDatabaseMissing('booking_reference_counters', ['next_value' => 2]);
    }

    // --- Extras and cross-border ----------------------------------------

    public function test_cross_border_details_are_carried_onto_the_booking(): void
    {
        $result = $this->bookings->create($this->request(
            quoteOptions: new QuoteOptions(
                extrasTotal: '250.00',
                crossBorderTotal: '1800.00',
                crossBorderCountry: 'ZW',
            ),
        ));

        $this->assertSame('250.00', $result->booking->extras_total);
        $this->assertSame('1800.00', $result->booking->cross_border_total);
        $this->assertSame('ZW', $result->booking->cross_border_country);
        $this->assertSame('4360.00', $result->booking->grand_total);
        $this->assertTrue($result->booking->isCrossBorder());
    }

    // --- References under load -------------------------------------------

    public function test_consecutive_bookings_take_consecutive_references(): void
    {
        $first = $this->bookings->create($this->request());

        $secondVehicle = Vehicle::factory()->create([
            'operator_id' => $this->vehicle->operator_id,
            'vehicle_class_id' => $this->vehicle->vehicle_class_id,
            'branch_id' => $this->branch->getKey(),
        ]);

        $second = $this->bookings->create($this->request(vehicle: $secondVehicle));

        $this->assertSame('BR-00001', $first->booking->reference);
        $this->assertSame('BR-00002', $second->booking->reference);
        $this->assertSame(2, Booking::query()->pluck('reference')->unique()->count());
    }

    // --- Helpers ---------------------------------------------------------

    private function hireWindow(): DateRange
    {
        return DateRange::of($this->now->addDays(7), $this->now->addDays(10));
    }

    private function request(
        ?Vehicle $vehicle = null,
        ?DateRange $range = null,
        ?Branch $pickupBranch = null,
        string $paymentMethodCode = 'bank_transfer',
        bool $payInFull = false,
        ?QuoteOptions $quoteOptions = null,
        ?Customer $verifiedCustomer = null,
    ): BookingRequest {
        $vehicle ??= $this->vehicle;
        $branch = $pickupBranch ?? $this->branch;

        return new BookingRequest(
            vehicle: $vehicle,
            range: $range ?? $this->hireWindow(),
            pickupBranch: $branch,
            dropoffBranch: $branch,
            customer: new CustomerDetails(
                fullName: 'Chanda Mwale',
                email: 'known@example.com',
                phone: '0977123456',
            ),
            paymentMethodCode: $paymentMethodCode,
            payInFull: $payInFull,
            termsVersion: '2026-08-01',
            quoteOptions: $quoteOptions ?? QuoteOptions::none(),
            verifiedCustomer: $verifiedCustomer,
        );
    }
}
