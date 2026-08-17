<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Contracts\PricingServiceContract;
use App\Enums\VehicleStatus;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browsing one class and every car in it.
 *
 * This page exists because the home page used to show a single vehicle
 * standing in for a whole class, which told a customer the operator had one
 * Corolla when he has four.
 *
 * The assertions that earn their place are about what it must NOT do. It has no
 * dates, so it cannot quote a hire — spec §1.2 requires the all-in price to be
 * identical from search to checkout, and a total invented here would be the
 * first thing to drift. It shows a daily rate, labelled as one, and sends
 * anybody who wants a real figure through search.
 */
final class VehicleClassPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-06T06:00:00Z'));

        $this->seed(SettingsSeeder::class);
    }

    public function test_it_lists_every_bookable_vehicle_in_the_class(): void
    {
        [$branch, $class] = $this->fleet();

        $corolla = $this->vehicle($branch, $class, ['make' => 'Toyota', 'model' => 'Corolla']);
        $demio = $this->vehicle($branch, $class, ['make' => 'Mazda', 'model' => 'Demio']);

        $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertSuccessful()
            ->assertSee($class->name)
            // The whole point: both cars, not one standing in for the class.
            ->assertSee('Toyota Corolla')
            ->assertSee('Mazda Demio')
            ->assertSee('2 vehicles in this range');
    }

    /**
     * A car off the road is not an option, so it is not offered as one.
     */
    public function test_a_vehicle_off_the_road_is_not_listed(): void
    {
        [$branch, $class] = $this->fleet();

        $this->vehicle($branch, $class, ['make' => 'Toyota', 'model' => 'Corolla']);
        $this->vehicle($branch, $class, [
            'make' => 'Toyota',
            'model' => 'Vitz',
            'status' => VehicleStatus::Maintenance,
        ]);

        $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertSuccessful()
            ->assertSee('Toyota Corolla')
            ->assertDontSee('Toyota Vitz')
            ->assertSee('1 vehicle in this range');
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * The figure shown is the lowest DAILY RATE any car in the class charges,
     * asserted against PricingService rather than a literal — a test comparing
     * hardcoded strings would pass even if the page invented its own
     * arithmetic, which is the exact failure §1.2 exists to prevent.
     *
     * It is taken across the vehicles, not read off the class, because an
     * override can be higher or lower than its class figure. Reading the class
     * would advertise a rate no actual car charges.
     */
    public function test_the_from_price_is_the_lowest_rate_any_car_actually_charges(): void
    {
        [$branch, $class] = $this->fleet(['daily_rate' => '1000.00']);

        // Inherits 1000.00.
        $inheriting = $this->vehicle($branch, $class);
        // Overridden DEARER than its class, so it must not set the "from".
        $this->vehicle($branch, $class, ['daily_rate' => '1800.00']);

        $expected = app(PricingServiceContract::class)->dailyRateFor($inheriting);

        $this->assertSame('1000.00', $expected);

        $response = $this->get(route('classes.show', ['slug' => $class->slug]));

        $response->assertSuccessful();
        $this->assertSame($expected, $response->viewData('fromDailyRate'));
        $response->assertSee(number_format((float) $expected, 2));
    }

    /**
     * When every car is overridden dearer than the class, the class figure is
     * not achievable by anybody and must not be advertised.
     */
    public function test_the_from_price_ignores_a_class_rate_no_car_charges(): void
    {
        [$branch, $class] = $this->fleet(['daily_rate' => '1000.00']);

        $this->vehicle($branch, $class, ['daily_rate' => '1800.00']);
        $this->vehicle($branch, $class, ['daily_rate' => '2400.00']);

        $response = $this->get(route('classes.show', ['slug' => $class->slug]));

        // 1800, not 1000 — nobody can hire this class for a thousand.
        $this->assertSame('1800.00', $response->viewData('fromDailyRate'));
    }

    /**
     * Spec §1.2. This page has no dates, so it has no hire and therefore no
     * total. Anything calling itself a total here would be the first figure to
     * disagree with checkout.
     */
    public function test_it_quotes_a_daily_rate_and_never_a_total(): void
    {
        [$branch, $class] = $this->fleet();
        $this->vehicle($branch, $class);

        $response = $this->get(route('classes.show', ['slug' => $class->slug]));

        $response->assertSuccessful()
            ->assertSee('per day')
            // No booking can be started from here — that needs dates.
            ->assertDontSee('Reserve this vehicle')
            ->assertDontSee('Total for');
    }

    /**
     * Every card leads to the product page.
     *
     * The vehicle page validates branch, pickup and dropoff as required and
     * 404s without them, so a link missing any one lands on an error rather
     * than a car. The branch must be the vehicle's own for the same reason —
     * that page 404s when the branch in the URL is not where the car is.
     */
    public function test_every_vehicle_card_links_to_the_product_page(): void
    {
        [$branch, $class] = $this->fleet();

        $corolla = $this->vehicle($branch, $class, ['make' => 'Toyota', 'model' => 'Corolla']);
        $demio = $this->vehicle($branch, $class, ['make' => 'Mazda', 'model' => 'Demio']);

        $response = $this->get(route('classes.show', ['slug' => $class->slug]));
        $response->assertSuccessful();

        foreach ([$corolla, $demio] as $vehicle) {
            $this->assertStringContainsString(
                '/vehicles/'.$vehicle->getKey(),
                $response->getContent(),
            );
        }

        // Following one must reach a real priced page, not a 404.
        $this->get(route('vehicles.show', [
            'vehicle' => $corolla->getKey(),
            'branch' => $corolla->branch_id,
            'pickup' => $response->viewData('defaultPickup'),
            'dropoff' => $response->viewData('defaultDropoff'),
        ]))
            ->assertSuccessful()
            ->assertSee('Reserve this vehicle');
    }

    /**
     * The dates in the card links must be the ones the form on the same page
     * is showing. If they differed, a customer would click a car while looking
     * at one set of days and arrive priced for another.
     */
    public function test_the_card_links_and_the_form_agree_on_dates(): void
    {
        [$branch, $class] = $this->fleet();
        $this->vehicle($branch, $class);

        $response = $this->get(route('classes.show', ['slug' => $class->slug]));
        $response->assertSuccessful();

        $pickup = $response->viewData('defaultPickup');
        $dropoff = $response->viewData('defaultDropoff');

        $this->assertStringContainsString('value="'.$pickup.'"', $response->getContent());
        $this->assertStringContainsString('pickup='.urlencode($pickup), $response->getContent());
        $this->assertStringContainsString('dropoff='.urlencode($dropoff), $response->getContent());
    }

    /**
     * Spec §6 and §10: the deposit must never first appear at the counter, and
     * the excess must be stated before payment. This is one of the earliest
     * pages either can appear on.
     */
    public function test_it_states_the_deposit_and_the_excess(): void
    {
        [$branch, $class] = $this->fleet([
            'security_deposit_amount' => '2500.00',
            'insurance_excess_amount' => '5000.00',
        ]);
        $this->vehicle($branch, $class);

        $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertSuccessful()
            ->assertSee('2,500.00')
            ->assertSee('5,000.00')
            ->assertSee('remain liable');
    }

    /**
     * A class nobody has priced cannot be sold, so it must not have a shop
     * window either. `AvailabilityService` already withholds it from search;
     * this page bypasses search entirely, so it enforces the same rule.
     */
    public function test_a_class_awaiting_a_pricing_decision_is_not_found(): void
    {
        [$branch, $class] = $this->fleet(['insurance_excess_amount' => null]);
        $this->vehicle($branch, $class);

        $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertNotFound();
    }

    public function test_a_retired_class_is_not_found(): void
    {
        [$branch, $class] = $this->fleet(['is_active' => false]);
        $this->vehicle($branch, $class);

        $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertNotFound();
    }

    public function test_an_unknown_class_is_not_found(): void
    {
        $this->get(route('classes.show', ['slug' => 'no-such-class']))
            ->assertNotFound();
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Branch, 1: VehicleClass}
     */
    private function fleet(array $attributes = []): array
    {
        $branch = Branch::factory()->create(['name' => 'Lusaka Branch']);

        $class = VehicleClass::factory()->create(array_merge([
            'operator_id' => $branch->operator_id,
            'name' => 'Economy',
            'slug' => 'economy',
            'description' => 'Small automatic hatchbacks for town driving.',
        ], $attributes));

        return [$branch, $class];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function vehicle(Branch $branch, VehicleClass $class, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->create(array_merge([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ], $attributes));
    }
}
