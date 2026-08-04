<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BasketServiceContract;
use App\Contracts\QuoteServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\Basket;
use App\DataTransferObjects\DateRange;
use App\DataTransferObjects\QuoteOptions;
use App\DataTransferObjects\SearchCriteria;
use App\Enums\InsurancePriceMode;
use App\Enums\SettingKey;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

final class BasketServiceTest extends TestCase
{
    use RefreshDatabase;

    private BasketServiceContract $baskets;

    private CarbonImmutable $now;

    private Vehicle $vehicle;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $class = VehicleClass::factory()->create([
            'daily_rate' => '650.00',
            'insurance_price' => '120.00',
            'insurance_price_mode' => InsurancePriceMode::PerDay,
            'security_deposit_amount' => '1500.00',
        ]);

        $this->branch = Branch::factory()->create(['operator_id' => $class->operator_id]);

        $this->vehicle = Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        $this->baskets = app(BasketServiceContract::class);
    }

    public function test_a_basket_can_be_placed_and_read_back(): void
    {
        $this->baskets->place($this->basket());

        $current = $this->baskets->current();

        $this->assertInstanceOf(Basket::class, $current);
        $this->assertSame($this->vehicle->getKey(), $current->vehicleId);
        $this->assertSame($this->branch->getKey(), $current->pickupBranchId);
    }

    public function test_there_is_no_basket_to_begin_with(): void
    {
        $this->assertNull($this->baskets->current());
    }

    /**
     * Spec §1.1: the quoted price is guaranteed for the life of the basket.
     */
    public function test_the_price_is_frozen_against_a_later_rate_change(): void
    {
        $this->baskets->place($this->basket());

        $quotedAtSearch = $this->baskets->current()->quote->grandTotal;

        VehicleClass::query()
            ->whereKey($this->vehicle->vehicle_class_id)
            ->update(['daily_rate' => '2000.00']);

        // Re-reading the basket must not re-price it. Honouring the new rate
        // mid-checkout is exactly what §1.2 forbids.
        $this->assertSame($quotedAtSearch, $this->baskets->current()->quote->grandTotal);
        $this->assertSame('2310.00', $this->baskets->current()->quote->grandTotal);
    }

    public function test_the_frozen_quote_survives_the_round_trip_intact(): void
    {
        // Everything is flattened to scalars for the session, so this proves
        // nothing is lost or coerced on the way back.
        $original = $this->basket(
            options: new QuoteOptions(
                extrasTotal: '250.00',
                crossBorderTotal: '1800.00',
                crossBorderCountry: 'ZW',
            ),
        );

        $this->baskets->place($original);
        $restored = $this->baskets->current();

        $this->assertTrue($original->quote->matches($restored->quote));
        $this->assertSame('250.00', $restored->quote->extrasTotal);
        $this->assertSame('1800.00', $restored->quote->crossBorderTotal);
        $this->assertSame('ZW', $restored->quote->crossBorderCountry);
        $this->assertSame(InsurancePriceMode::PerDay, $restored->quote->insurancePriceMode);
        $this->assertTrue($original->range->start->equalTo($restored->range->start));
    }

    // --- Expiry ----------------------------------------------------------

    public function test_a_basket_lapses_after_thirty_minutes_of_inactivity(): void
    {
        $this->baskets->place($this->basket());

        $this->assertNotNull($this->baskets->current($this->now->addMinutes(29)));
        $this->assertNull($this->baskets->current($this->now->addMinutes(31)));
    }

    public function test_activity_restarts_the_inactivity_window(): void
    {
        // Thirty minutes of inactivity, not thirty minutes of age. A customer
        // still working through checkout must not have the basket pulled out
        // from under them.
        $this->baskets->place($this->basket());

        $this->baskets->touch($this->now->addMinutes(25));

        $this->assertNotNull($this->baskets->current($this->now->addMinutes(50)));
        $this->assertNull($this->baskets->current($this->now->addMinutes(56)));
    }

    public function test_touching_a_lapsed_basket_does_not_revive_it(): void
    {
        $this->baskets->place($this->basket());

        $this->assertNull($this->baskets->touch($this->now->addMinutes(31)));
        $this->assertNull($this->baskets->current($this->now->addMinutes(31)));
    }

    public function test_the_lifetime_comes_from_settings(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::BasketTtlMinutes, 5);

        $this->baskets->place($this->basket());

        $this->assertSame(5, $this->baskets->ttlMinutes());
        $this->assertNull($this->baskets->current($this->now->addMinutes(6)));
    }

    public function test_the_expiry_time_is_reported_for_a_live_basket(): void
    {
        $this->baskets->place($this->basket());

        $this->assertTrue(
            $this->baskets->expiresAt()->equalTo($this->now->addMinutes(30)),
        );
    }

    /**
     * Spec §1.1: on expiry the customer returns to search with dates pre-filled.
     */
    public function test_an_expired_basket_leaves_the_search_criteria_behind(): void
    {
        $this->baskets->place($this->basket());

        $this->assertNull($this->baskets->current($this->now->addMinutes(31)));

        $criteria = $this->baskets->lastSearch();

        $this->assertInstanceOf(SearchCriteria::class, $criteria);
        $this->assertSame($this->branch->getKey(), $criteria->pickupBranchId);
        $this->assertTrue($criteria->range->start->equalTo($this->hireWindow()->start));
        $this->assertTrue($criteria->range->end->equalTo($this->hireWindow()->end));
    }

    // --- Session behaviour ----------------------------------------------

    /**
     * Spec §14.1: the basket survives a sign-in prompt.
     */
    public function test_the_basket_survives_a_session_id_regeneration(): void
    {
        // Signing in regenerates the session ID to prevent fixation. If the
        // basket did not migrate with it, every customer who signed in at
        // checkout would lose their trip.
        $this->baskets->place($this->basket());

        Session::regenerate();

        $this->assertNotNull($this->baskets->current());
        $this->assertSame($this->vehicle->getKey(), $this->baskets->current()->vehicleId);
    }

    public function test_forgetting_a_basket_keeps_the_search_criteria(): void
    {
        $this->baskets->place($this->basket());

        $this->baskets->forget();

        $this->assertNull($this->baskets->current());
        $this->assertInstanceOf(SearchCriteria::class, $this->baskets->lastSearch());
    }

    public function test_rubbish_in_the_session_presents_as_an_empty_basket(): void
    {
        // A basket written by an older deploy, or one that has been tampered
        // with, must not throw a customer into a 500 page mid-checkout.
        Session::put('carhire.basket', ['nonsense' => true]);

        $this->assertNull($this->baskets->current());
        $this->assertFalse(Session::has('carhire.basket'));
    }

    // --- Helpers ---------------------------------------------------------

    private function hireWindow(): DateRange
    {
        return DateRange::of('2026-09-14T09:00:00Z', '2026-09-17T09:00:00Z');
    }

    private function basket(?QuoteOptions $options = null): Basket
    {
        $options ??= QuoteOptions::none();
        $range = $this->hireWindow();

        return Basket::start(
            vehicleId: $this->vehicle->getKey(),
            pickupBranchId: $this->branch->getKey(),
            dropoffBranchId: $this->branch->getKey(),
            range: $range,
            options: $options,
            quote: app(QuoteServiceContract::class)->quoteFor($this->vehicle, $range, $options),
        );
    }
}
