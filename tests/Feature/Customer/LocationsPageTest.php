<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\VehicleStatus;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the operator trades from, as a customer sees it.
 *
 * Branches have existed since Phase 1 and reached a customer only as options in
 * a dropdown, so somebody deciding whether to hire here at all could not find
 * an address or a telephone number.
 *
 * The assertion that earns its place is the one about UNPUBLISHED hours. Spec
 * §15.8 is the business's to answer and the columns are nullable for that
 * reason; the failure mode is a page that prints a plausible "08:00–17:00"
 * because a blank looked untidy, and somebody drives to a closed gate.
 */
final class LocationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_open_branches_with_their_details(): void
    {
        $this->branch([
            'name' => 'Lusaka Branch',
            'city' => 'Lusaka',
            'address' => 'Plot 42, Great East Road',
            'phone_e164' => '+260971234567',
        ]);

        $this->get(route('locations'))
            ->assertSuccessful()
            ->assertSee('Lusaka Branch')
            ->assertSee('Plot 42, Great East Road')
            ->assertSee('+260971234567');
    }

    /**
     * `is_active` is the off switch that replaces deletion. A closed branch is
     * not somewhere anybody can collect a car.
     */
    public function test_a_closed_branch_is_not_listed(): void
    {
        $this->branch(['name' => 'Lusaka Branch']);
        $this->branch(['name' => 'Kitwe Branch', 'code' => 'KIT', 'is_active' => false]);

        $this->get(route('locations'))
            ->assertSuccessful()
            ->assertSee('Lusaka Branch')
            ->assertDontSee('Kitwe Branch');
    }

    public function test_published_hours_are_shown(): void
    {
        $this->branch(['opens_at' => '08:00', 'closes_at' => '17:00']);

        $this->get(route('locations'))
            ->assertSuccessful()
            ->assertSee('08:00 – 17:00', escape: false);
    }

    /**
     * THE ONE THAT MATTERS. Nothing may be invented to fill the gap.
     */
    public function test_unpublished_hours_say_so_rather_than_inventing_any(): void
    {
        $this->branch();

        $response = $this->get(route('locations'))->assertSuccessful();

        $response->assertSee('Opening hours not published', escape: false);

        // The specific shape of the failure being guarded against: a sensible
        // looking default appearing because a blank looked untidy.
        $this->assertStringNotContainsString('08:00', $response->getContent());
    }

    public function test_after_hours_collection_is_only_mentioned_where_it_is_offered(): void
    {
        $this->branch(['after_hours_pickup' => false]);

        $this->get(route('locations'))
            ->assertSuccessful()
            ->assertDontSee('Collection outside these hours');
    }

    /**
     * Same rule as the home page: a car in maintenance is not an option, so
     * counting it would advertise a fleet the branch cannot supply.
     */
    public function test_the_vehicle_count_excludes_cars_off_the_road(): void
    {
        $branch = $this->branch();
        $class = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);

        $this->vehicle($branch, $class);
        $this->vehicle($branch, $class, ['status' => VehicleStatus::Maintenance]);

        $this->get(route('locations'))
            ->assertSuccessful()
            ->assertSee('1 vehicle');
    }

    /**
     * Reachable on a fresh install before anybody has set the platform up. An
     * empty grid reads as a broken page.
     */
    public function test_it_says_so_when_there_are_no_branches(): void
    {
        $this->get(route('locations'))
            ->assertSuccessful()
            ->assertSee('No branches published yet');
    }

    public function test_the_page_is_reachable_from_the_site_header(): void
    {
        $this->branch();

        $this->get(route('home'))
            ->assertSuccessful()
            ->assertSee(route('locations'), escape: false);
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function branch(array $attributes = []): Branch
    {
        return Branch::factory()->create(array_merge([
            'opens_at' => null,
            'closes_at' => null,
        ], $attributes));
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
