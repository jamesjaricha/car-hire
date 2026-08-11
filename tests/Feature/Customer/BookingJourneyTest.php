<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\BookingStatus;
use App\Enums\VehicleStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoPaymentDetailsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search through to a payment reference, as a guest. Spec §1.1 to §5.
 *
 * The assertions that earn their place are the ones about money and about what
 * the customer is told, because this is the flow where somebody decides whether
 * to transfer real money to a stranger's account.
 */
final class BookingJourneyTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T06:00:00Z');
        $this->travelTo($this->now);

        $this->seed(SettingsSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->seed(DemoPaymentDetailsSeeder::class);
    }

    // --- Vehicle detail -----------------------------------------------------

    public function test_the_vehicle_page_prices_the_customers_dates(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $this->get($this->vehicleUrl($branch, $vehicle))
            ->assertSuccessful()
            ->assertSee('Economy')
            ->assertSee('Reserve this vehicle')
            // Spec §10: the excess must be stated before payment.
            ->assertSee('remain liable');
    }

    /**
     * A hand-altered branch in the URL must not produce a quote the operator
     * cannot honour — the vehicle is not at that branch.
     */
    public function test_a_vehicle_at_another_branch_is_not_found(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $elsewhere = Branch::factory()->create(['operator_id' => $branch->operator_id]);

        $this->get(route('vehicles.show', [
            'vehicle' => $vehicle->getKey(),
            'branch' => $elsewhere->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]))->assertNotFound();
    }

    // --- Basket -------------------------------------------------------------

    /**
     * Spec §1.1 and §1.2: the price quoted is frozen for the life of the
     * basket. Reserving claims nothing yet — only checkout takes a hold.
     */
    public function test_reserving_puts_the_quoted_price_in_the_basket(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $this->post(route('basket.store'), [
            'vehicle' => $vehicle->getKey(),
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ])->assertRedirect(route('checkout'));

        $this->get(route('checkout'))
            ->assertSuccessful()
            ->assertSee('Confirm your booking')
            ->assertSee('Pay the deposit');

        // No booking exists yet. A basket is not a reservation.
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('vehicle_holds', 0);
    }

    public function test_checkout_without_a_basket_sends_you_back_to_search(): void
    {
        $this->get(route('checkout'))->assertRedirect(route('home'));
    }

    /**
     * REGRESSION. Three controller paths flashed a `notice` and the layout
     * rendered none of them, so every one was silently dropped.
     *
     * These are the messages that matter most: each explains why something the
     * customer expected did not happen, and two of the three say "nothing has
     * been charged". A customer bounced back to search with no explanation
     * reasonably concludes the site threw their booking away — or worse, that
     * it took their money.
     */
    public function test_an_expired_basket_explains_itself_on_the_page(): void
    {
        $this->followingRedirects()
            ->get(route('checkout'))
            ->assertSuccessful()
            ->assertSee('Your basket has expired');
    }

    /**
     * The worst one to drop: they filled in the whole form before losing the
     * race, so they need telling that nothing was taken from them.
     */
    public function test_losing_the_vehicle_at_checkout_says_nothing_was_charged(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);

        // Somebody else takes it in the meantime.
        $vehicle->forceFill(['status' => VehicleStatus::Maintenance])->save();

        $this->followingRedirects()
            ->post(route('checkout.store'), $this->details())
            ->assertSuccessful()
            ->assertSee('Nothing has been charged');

        $this->assertDatabaseCount('bookings', 0);
    }

    // --- Checkout -----------------------------------------------------------

    public function test_a_guest_can_complete_a_booking_and_is_given_a_reference(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);

        $response = $this->post(route('checkout.store'), $this->details());

        $booking = Booking::query()->firstOrFail();

        $response->assertRedirect(route('booking.confirmation', ['reference' => $booking->reference]));

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        // Spec §14.3: an offline booking produces a hold and a payment record.
        $this->assertDatabaseCount('vehicle_holds', 1);
        $this->assertDatabaseCount('payments', 1);

        // Spec §1.3: a customer record is created from checkout, with no
        // account and no password.
        $this->assertDatabaseHas('customers', ['email' => 'chanda@example.com']);
    }

    public function test_the_terms_must_be_accepted(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);

        $this->post(route('checkout.store'), $this->details(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * Spec §1.4, and it is a security requirement rather than a courtesy.
     *
     * If checkout rendered differently for an address that already exists,
     * anybody could enumerate the operator's customer list by starting
     * checkouts with guessed emails.
     */
    public function test_checkout_reveals_nothing_about_an_existing_account(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $existing = Customer::factory()->create(['email' => 'chanda@example.com']);

        $this->reserve($branch, $vehicle);

        $response = $this->post(route('checkout.store'), $this->details());

        $booking = Booking::query()->firstOrFail();

        // Identical outcome to a first-time customer: straight through to the
        // confirmation, no error, nothing asking them to sign in.
        $response->assertRedirect(route('booking.confirmation', ['reference' => $booking->reference]));
        $response->assertSessionHasNoErrors();

        // §1.4: a matching email does NOT silently link. Guest checkout proves
        // nothing about identity, and attaching this booking to the existing
        // record would hand a stranger somebody else's history.
        $this->assertNotSame((int) $existing->getKey(), (int) $booking->customer_id);
    }

    /**
     * The other half of §1.4: nothing rendered to the customer may hint that
     * the address is already known. A page that says so lets anybody enumerate
     * the operator's customer list by starting checkouts with guessed emails.
     */
    public function test_no_wording_anywhere_suggests_an_account_already_exists(): void
    {
        [$branch, $vehicle] = $this->fleet();

        Customer::factory()->create(['email' => 'chanda@example.com']);

        $this->reserve($branch, $vehicle);
        $this->post(route('checkout.store'), $this->details());

        $booking = Booking::query()->firstOrFail();

        $confirmation = $this->get(route('booking.confirmation', ['reference' => $booking->reference]));

        foreach (['already have an account', 'already registered', 'sign in', 'log in', 'existing account'] as $tell) {
            $confirmation->assertDontSee($tell, escape: false);
        }
    }

    // --- Confirmation -------------------------------------------------------

    public function test_the_confirmation_shows_the_reference_and_instructions(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);
        $this->post(route('checkout.store'), $this->details());

        $booking = Booking::query()->firstOrFail();

        $this->get(route('booking.confirmation', ['reference' => $booking->reference]))
            ->assertSuccessful()
            ->assertSee($booking->reference.'-1')
            // §7.3: proof of payment never confirms a booking on its own, so
            // the page must not claim it is confirmed.
            ->assertSee('not confirmed until we have received your payment')
            // The operator's account details, merged by the adapter.
            ->assertSee('Demo Bank (not a real bank)')
            ->assertSee('0000000000');
    }

    /**
     * REGRESSION. The first version showed `balance_due`, which is the whole
     * grand total at this moment because nothing has been paid yet. Beside
     * "amount to pay now" that reads as two separate debts, and made a
     * ZMW 2,220 hire look like ZMW 3,330.
     */
    public function test_the_confirmation_shows_what_remains_after_this_payment(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);
        $this->post(route('checkout.store'), $this->details(['pay_in_full' => '0']));

        $booking = Booking::query()->firstOrFail();
        $payment = $booking->payments()->firstOrFail();

        $expectedRemaining = Money::subtract($booking->grand_total, $payment->expected_amount);

        $this->get(route('booking.confirmation', ['reference' => $booking->reference]))
            ->assertSuccessful()
            ->assertSee('The remaining '.$booking->currency.' '.number_format((float) $expectedRemaining, 2))
            // And crucially NOT the full total presented as still owing.
            ->assertDontSee('The remaining '.$booking->currency.' '.number_format((float) $booking->grand_total, 2));
    }

    public function test_paying_in_full_leaves_only_the_refundable_deposit(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);
        $this->post(route('checkout.store'), $this->details(['pay_in_full' => '1']));

        $booking = Booking::query()->firstOrFail();

        $this->get(route('booking.confirmation', ['reference' => $booking->reference]))
            ->assertSuccessful()
            ->assertSee('settles the hire in full');
    }

    /**
     * Spec §6, at the last possible moment before collection. It must never be
     * a surprise at the counter.
     */
    public function test_the_confirmation_restates_the_refundable_deposit(): void
    {
        [$branch, $vehicle] = $this->fleet();
        $this->reserve($branch, $vehicle);
        $this->post(route('checkout.store'), $this->details());

        $booking = Booking::query()->firstOrFail();

        $this->get(route('booking.confirmation', ['reference' => $booking->reference]))
            ->assertSuccessful()
            ->assertSee('refundable security deposit of '.$booking->currency.' '
                .number_format((float) $booking->security_deposit_amount, 2));
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->get(route('booking.confirmation', ['reference' => 'BR-99999']))
            ->assertNotFound();
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Chanda Mwansa',
            'email' => 'chanda@example.com',
            'phone' => '0977123456',
            'payment_method' => 'bank_transfer',
            'pay_in_full' => '0',
            'terms' => '1',
        ], $overrides);
    }

    private function reserve(Branch $branch, Vehicle $vehicle): void
    {
        $this->post(route('basket.store'), [
            'vehicle' => $vehicle->getKey(),
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]);
    }

    private function vehicleUrl(Branch $branch, Vehicle $vehicle): string
    {
        return route('vehicles.show', [
            'vehicle' => $vehicle->getKey(),
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]);
    }

    /**
     * @return array{0: Branch, 1: Vehicle}
     */
    private function fleet(): array
    {
        $branch = Branch::factory()->create();

        $class = VehicleClass::factory()->create([
            'operator_id' => $branch->operator_id,
            'name' => 'Economy',
        ]);

        $vehicle = Vehicle::factory()->create([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);

        return [$branch, $vehicle];
    }
}
