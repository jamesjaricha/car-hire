<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Database\Seeders\DemoFleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample fleet a demonstration is given on.
 *
 * Worth testing despite being development data, because the ways it breaks are
 * all silent. A class missing one §15 figure is withheld from search with
 * nothing on screen to say why; a seeder that is not idempotent destroys
 * photographs somebody uploaded through the panel. Neither shows up as an
 * error — the shop window just quietly holds fewer cars than it should.
 */
final class DemoFleetSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE ONE THAT MATTERS.
     *
     * `AvailabilityService` withholds a class holding a null in any of the
     * three §15 pricing columns, and `HomeController` filters on
     * `fullyPriced()` too. That behaviour is correct and must not be loosened
     * — see ARCHITECTURE §14 — but it means a demo class missing a figure
     * vanishes from the site with no error anywhere. Adding a seventh class
     * without its figures should fail here rather than at a demonstration.
     */
    public function test_every_seeded_class_is_sellable(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $classes = VehicleClass::query()->get();

        $this->assertGreaterThan(0, $classes->count());

        foreach ($classes as $class) {
            $this->assertSame(
                [],
                $class->missingPricingDecisions(),
                "Class [{$class->name}] is missing a §15 pricing decision and will not appear in search.",
            );
        }
    }

    /**
     * Slugs are set explicitly rather than by a model hook, because
     * `DatabaseSeeder` runs under `WithoutModelEvents` and a hook would never
     * fire. This has left derived columns null on a fresh migrate-and-seed
     * before, on this project and others.
     */
    public function test_every_class_has_a_slug(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $this->assertSame(
            0,
            VehicleClass::query()->whereNull('slug')->count(),
        );
    }

    /**
     * Re-running must not duplicate anything, and — the reason this is tested
     * rather than assumed — must not overwrite an edit somebody made in the
     * panel. Photographs live in `image_paths` on the class, so a seeder that
     * updated rather than created would silently discard an operator's
     * uploads on the next `db:seed`.
     */
    public function test_reseeding_neither_duplicates_nor_overwrites_panel_edits(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $classes = VehicleClass::query()->count();
        $vehicles = Vehicle::query()->count();

        // Stand in for an operator uploading photographs and correcting a rate.
        $economy = VehicleClass::query()->where('slug', 'economy')->firstOrFail();
        $economy->forceFill([
            'image_paths' => ['vehicle-classes/operator-upload.jpg'],
            'daily_rate' => '999.00',
        ])->save();

        $this->seed(DemoFleetSeeder::class);

        $this->assertSame($classes, VehicleClass::query()->count());
        $this->assertSame($vehicles, Vehicle::query()->count());

        $economy->refresh();

        $this->assertSame(['vehicle-classes/operator-upload.jpg'], $economy->image_paths);
        $this->assertSame('999.00', $economy->daily_rate);
    }

    /**
     * The home page shows one vehicle per class, uncapped, in a three-column
     * grid. Six classes land as two full rows; a count that is not a multiple
     * of three leaves a ragged last row, which is what the section looked like
     * when three classes met a four-column grid.
     *
     * Asserted as a multiple of three rather than as exactly six, so adding a
     * seventh class fails here — the reminder being that the grid needs a
     * decision, not that seven classes are wrong.
     */
    public function test_the_fleet_fills_whole_rows_of_the_home_page_grid(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $featured = Vehicle::query()
            ->with('vehicleClass')
            ->bookable()
            ->whereHas('vehicleClass', fn ($query) => $query->active()->fullyPriced())
            ->get()
            ->unique('vehicle_class_id');

        $this->assertGreaterThanOrEqual(6, $featured->count());
        $this->assertSame(
            0,
            $featured->count() % 3,
            "The home page grid is three columns; {$featured->count()} classes leaves a ragged last row.",
        );
    }

    /**
     * Both branches must return results, or a demonstration that switches
     * branch shows an empty page and looks broken.
     */
    public function test_both_branches_hold_bookable_vehicles(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $branches = Branch::query()->active()->get();

        $this->assertGreaterThanOrEqual(2, $branches->count());

        foreach ($branches as $branch) {
            $this->assertGreaterThan(
                0,
                Vehicle::query()->bookable()->where('branch_id', $branch->getKey())->count(),
                "Branch [{$branch->name}] has no bookable vehicle and will show an empty search.",
            );
        }
    }

    /**
     * Seeded on purpose: it must not appear in a search, and having it in the
     * fleet makes that checkable in a browser rather than only in a test.
     */
    public function test_the_maintenance_vehicle_is_not_bookable(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $offRoad = Vehicle::query()->where('registration', 'ABC 1103')->firstOrFail();

        $this->assertFalse($offRoad->status->isBookable());
    }

    /**
     * One vehicle carries its own rate, so the class-to-vehicle override chain
     * is exercised by the demo data rather than only by unit tests.
     */
    public function test_one_vehicle_overrides_its_class_rate(): void
    {
        $this->seed(DemoFleetSeeder::class);

        $landCruiser = Vehicle::query()->where('registration', 'ABC 5501')->firstOrFail();

        $this->assertNotNull($landCruiser->daily_rate);
        $this->assertNotSame(
            $landCruiser->vehicleClass->daily_rate,
            $landCruiser->daily_rate,
        );
    }
}
