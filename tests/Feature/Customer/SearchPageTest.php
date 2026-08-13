<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Contracts\QuoteServiceContract;
use App\DataTransferObjects\DateRange;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop window. Spec §1.1: a guest reaches a real price without entering a
 * single personal detail.
 *
 * The assertions worth reading are the two about money. §1.2 requires the price
 * here to equal the price at checkout, and §6 requires the refundable security
 * deposit to appear from search results onward and never first at the counter —
 * so both are asserted against what `QuoteService` actually computed rather
 * than against a hardcoded string, which would pass even if the page invented
 * its own arithmetic.
 */
final class SearchPageTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T06:00:00Z');
        $this->travelTo($this->now);

        $this->seed(SettingsSeeder::class);
    }

    public function test_the_home_page_renders_with_the_bookable_branches(): void
    {
        $open = Branch::factory()->create(['name' => 'Lusaka Branch', 'is_active' => true]);
        $closed = Branch::factory()->create(['name' => 'Kitwe Branch', 'is_active' => false]);

        $this->get(route('home'))
            ->assertSuccessful()
            ->assertSee($open->name)
            // A branch the operator has closed must not be offered.
            ->assertDontSee($closed->name);
    }

    /**
     * Spec §1.1. No session, no account, no contact details — just a price.
     */
    public function test_a_guest_can_search_and_see_a_priced_vehicle(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $response = $this->get($this->searchUrl($branch));

        $response->assertSuccessful()
            ->assertSee($vehicle->vehicleClass->name)
            ->assertSee('1 vehicle type available');
    }

    /**
     * Spec §1.2: the price shown here is the price charged later. Asserted
     * against the quote service rather than a literal, so the page cannot
     * quietly compute its own.
     */
    public function test_the_displayed_total_is_the_quoted_total(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $quote = app(QuoteServiceContract::class)->quoteFor($vehicle, $this->range());

        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertSee(number_format((float) $quote->grandTotal, 2))
            // And the daily rate beside it, so a customer can check the sum.
            ->assertSee(number_format((float) $quote->dailyRate, 2));
    }

    /**
     * Spec §6: "must never first appear at the counter". Search results are the
     * earliest place it can appear, so this is where the requirement bites.
     */
    public function test_the_refundable_security_deposit_is_shown_in_search_results(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $quote = app(QuoteServiceContract::class)->quoteFor($vehicle, $this->range());

        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertSee(number_format((float) $quote->securityDepositAmount, 2))
            ->assertSee('Refundable deposit');
    }

    public function test_the_price_is_stated_as_including_insurance(): void
    {
        [$branch] = $this->fleet();

        // Spec §1.2 and §10: the waiver is mandatory and already in the price.
        // Saying so is what stops it reading as a charge added later.
        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertSee('insurance included');
    }

    /**
     * A class nobody has priced is withheld from sale, so it must not appear
     * here either — this is the customer-facing half of that guarantee.
     */
    public function test_a_class_awaiting_pricing_decisions_is_not_offered(): void
    {
        $branch = Branch::factory()->create();

        $class = VehicleClass::factory()->create([
            'operator_id' => $branch->operator_id,
            'insurance_excess_amount' => null,
        ]);

        Vehicle::factory()->create([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);

        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertDontSee($class->name)
            ->assertSee('Nothing available');
    }

    public function test_an_empty_result_explains_what_to_change(): void
    {
        $branch = Branch::factory()->create();

        // A dead end that says nothing is a dead end the customer leaves.
        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertSee('Nothing available for those dates')
            ->assertSee('another branch');
    }

    /**
     * The message is asserted, not just the failure.
     *
     * It first reused the domain exception's own text, which reads
     * "Given start [2026-09-20T07:00:00+00:00]…" — internals, in UTC, showing
     * times two hours from the ones the customer typed. Correct in a log,
     * baffling on a search page.
     */
    public function test_a_return_date_before_the_pickup_is_refused(): void
    {
        [$branch] = $this->fleet();

        $response = $this->get(route('search', [
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-18T09:00',
        ]));

        $response->assertSessionHasErrors([
            'dates' => 'Your return date needs to be after your pick-up date.',
        ]);

        // No raw timestamps, no bracketed internals.
        $this->assertStringNotContainsString(
            '+00:00',
            (string) session('errors')?->first('dates'),
        );
    }

    public function test_an_unknown_branch_is_refused(): void
    {
        $this->get(route('search', [
            'branch' => 999999,
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]))->assertSessionHasErrors('branch');
    }

    /**
     * REGRESSION. The search form only rendered the `dates` error key, so a
     * failure on `branch`, `pickup` or `dropoff` — reachable with a
     * hand-altered URL, or an emptied required field before JavaScript
     * validation runs — sent the customer back to a page that gave no reason
     * why nothing happened. `checkout.blade.php` already had this fixed; the
     * search form did not.
     */
    public function test_a_branch_validation_failure_is_shown_on_the_page(): void
    {
        $this->followingRedirects()
            ->get(route('search', [
                'branch' => 999999,
                'pickup' => '2026-09-20T09:00',
                'dropoff' => '2026-09-23T09:00',
            ]))
            ->assertSuccessful()
            ->assertSee('selected branch is invalid', escape: false);
    }

    public function test_a_missing_pickup_is_shown_on_the_page(): void
    {
        [$branch] = $this->fleet();

        $this->followingRedirects()
            ->get(route('search', [
                'branch' => $branch->getKey(),
                'pickup' => '',
                'dropoff' => '2026-09-23T09:00',
            ]))
            ->assertSuccessful()
            ->assertSee('pickup field is required', escape: false);
    }

    /**
     * A failed submit must not silently reset the branch the customer chose
     * back to the first one in the list — that reads as the site having
     * ignored their answer rather than flagging a problem with a different
     * field.
     */
    public function test_a_failed_submit_keeps_the_chosen_branch_selected(): void
    {
        [$branch] = $this->fleet();
        $other = Branch::factory()->create(['name' => 'Kitwe Branch']);

        $response = $this->followingRedirects()->get(route('search', [
            'branch' => $other->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-18T09:00',
        ]));

        $response->assertSuccessful();
        $this->assertMatchesRegularExpression(
            '/<option value="'.$other->getKey().'" selected/',
            $response->getContent(),
        );
    }

    /**
     * ARCHITECTURE §5: the two inputs are wall-clock Lusaka, everything stored
     * is UTC, and the conversion happens at this edge. Zambia is UTC+2, so an
     * 09:00 pickup is 07:00 UTC — getting this wrong moves somebody's
     * collection by two hours.
     */
    public function test_the_entered_times_are_read_as_zambian_local_time(): void
    {
        [$branch, $vehicle] = $this->fleet();

        $response = $this->get(route('search', [
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]));

        $range = $response->viewData('range');

        $this->assertSame('2026-09-20T07:00:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-09-23T07:00:00+00:00', $range->end->toIso8601String());
    }

    // --- Fixtures -----------------------------------------------------------

    private function range(): DateRange
    {
        return DateRange::of(
            CarbonImmutable::parse('2026-09-20T09:00', 'Africa/Lusaka')->utc(),
            CarbonImmutable::parse('2026-09-23T09:00', 'Africa/Lusaka')->utc(),
        );
    }

    private function searchUrl(Branch $branch): string
    {
        return route('search', [
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]);
    }

    /**
     * One branch with one fully priced vehicle in it.
     *
     * @return array{0: Branch, 1: Vehicle}
     */
    private function fleet(): array
    {
        $branch = Branch::factory()->create(['name' => 'Lusaka Branch']);

        $class = VehicleClass::factory()->create([
            'operator_id' => $branch->operator_id,
            'name' => 'Economy',
        ]);

        $vehicle = Vehicle::factory()->create([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);

        $vehicle->setRelation('vehicleClass', $class);

        return [$branch, $vehicle];
    }
}
