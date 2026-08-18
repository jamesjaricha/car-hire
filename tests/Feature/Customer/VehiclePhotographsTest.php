<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Photographs of the actual car, and the honesty about it when there are none.
 *
 * WHY THIS EXISTS
 *
 * Photographs used to live only on the class, on the reasoning that an operator
 * with four Corollas photographs the Corolla once. That is true of his effort
 * and false of the customer's decision: this platform locks a SPECIFIC vehicle
 * row, so somebody is hiring a particular registration, and two cars in one
 * class differ in colour, age and condition. The operator's own verdict on
 * showing a stand-in was that it "looks like a scam website".
 *
 * The assertions that earn their place are not about images rendering. They are
 * about the platform never CLAIMING a picture is of a car it is not:
 *
 *  - own photographs replace the class gallery rather than joining it, so a
 *    customer never sees this car and another car side by side, unlabelled;
 *  - the vehicle page states plainly when it is showing a class photograph,
 *    because that is the screen where money gets committed;
 *  - the admin worklist counts an inherited gallery as MISSING, since a car
 *    borrowing its class's pictures is exactly what this work exists to find.
 */
final class VehiclePhotographsTest extends TestCase
{
    use RefreshDatabase;

    private const CAR_PHOTO = 'vehicles/abc-1234-front.jpg';

    private const CLASS_PHOTO = 'vehicle-classes/economy.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-06T06:00:00Z'));

        $this->seed(SettingsSeeder::class);
    }

    // --- The fallback chain -------------------------------------------------

    public function test_a_vehicle_with_its_own_photographs_uses_them(): void
    {
        [, $class] = $this->fleet();

        $vehicle = $this->vehicle($class, [
            'image_paths' => [self::CAR_PHOTO],
        ]);

        $this->assertTrue($vehicle->hasOwnImages());
        $this->assertSame(self::CAR_PHOTO, $vehicle->primaryImagePath());
    }

    public function test_a_vehicle_without_photographs_falls_back_to_its_class(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class);

        $this->assertFalse($vehicle->hasOwnImages());
        $this->assertSame(self::CLASS_PHOTO, $vehicle->primaryImagePath());
    }

    /**
     * THE ONE THAT MATTERS FOR TRUST.
     *
     * If own photographs were merged with the class gallery, a customer would
     * be shown this car and somebody else's car in the same strip, with nothing
     * saying which was which — a worse misrepresentation than the class-only
     * gallery this replaced, because it looks specific.
     */
    public function test_own_photographs_replace_the_class_gallery_rather_than_joining_it(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class, [
            'image_paths' => [self::CAR_PHOTO],
        ]);

        $this->assertSame([self::CAR_PHOTO], $vehicle->imagePaths());
        $this->assertNotContains(self::CLASS_PHOTO, $vehicle->imagePaths());
    }

    public function test_a_vehicle_with_no_photographs_anywhere_has_none(): void
    {
        [, $class] = $this->fleet();

        $this->assertNull($this->vehicle($class)->primaryImagePath());
    }

    /**
     * An emptied Filament upload writes `[]`, not null. Both mean "no
     * photograph", and code testing only for null would report a gallery
     * somebody had just cleared as populated.
     */
    public function test_an_emptied_gallery_counts_as_no_photographs(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class, ['image_paths' => []]);

        $this->assertFalse($vehicle->hasOwnImages());
        // Still inherits, because empty means inherit rather than "none".
        $this->assertSame(self::CLASS_PHOTO, $vehicle->primaryImagePath());
    }

    // --- The admin worklist -------------------------------------------------

    /**
     * A car inheriting its class's pictures LOOKS finished on the site and is
     * precisely the case this queue exists to surface. Counting it as done
     * would empty the list exactly where the work is.
     */
    public function test_the_worklist_includes_a_vehicle_that_is_only_borrowing_class_photographs(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $borrowing = $this->vehicle($class, ['registration' => 'BOR 001']);
        $emptied = $this->vehicle($class, ['registration' => 'EMP 001', 'image_paths' => []]);
        $photographed = $this->vehicle($class, [
            'registration' => 'OWN 001',
            'image_paths' => [self::CAR_PHOTO],
        ]);

        $awaiting = Vehicle::query()->withoutImages()->pluck('registration')->all();

        $this->assertContains($borrowing->registration, $awaiting);
        $this->assertContains($emptied->registration, $awaiting);
        $this->assertNotContains($photographed->registration, $awaiting);
    }

    // --- What the customer sees ---------------------------------------------

    public function test_a_search_result_shows_the_cars_own_photograph(): void
    {
        [$branch, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $content = $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString(self::CAR_PHOTO, $content);
        $this->assertStringNotContainsString(self::CLASS_PHOTO, $content);
    }

    /**
     * "Or similar" is the hire trade's standard hedge and it was harmless while
     * every card carried a class photograph. Printed under a picture of THIS
     * car it contradicts the picture — the customer is shown a specific Corolla
     * and told in the next line they may get a different one, which spends the
     * trust the photograph was added to earn.
     */
    public function test_a_card_showing_this_car_does_not_hedge_with_or_similar(): void
    {
        [$branch, $class] = $this->fleet();

        $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertDontSee('or similar');
    }

    /**
     * And the other direction, which is the half that keeps it honest: when the
     * picture IS a stand-in, the hedge belongs there.
     */
    public function test_a_card_showing_a_stand_in_photograph_still_hedges(): void
    {
        [$branch, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $this->vehicle($class);

        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertSee('or similar');
    }

    public function test_the_class_page_shows_each_cars_own_photograph(): void
    {
        [, $class] = $this->fleet();

        $this->vehicle($class, [
            'registration' => 'AAA 111',
            'image_paths' => ['vehicles/aaa-111.jpg'],
        ]);
        $this->vehicle($class, [
            'registration' => 'BBB 222',
            'image_paths' => ['vehicles/bbb-222.jpg'],
        ]);

        $content = $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertSuccessful()
            ->getContent();

        // Two cars, two different pictures — the whole point of the screen.
        $this->assertStringContainsString('vehicles/aaa-111.jpg', $content);
        $this->assertStringContainsString('vehicles/bbb-222.jpg', $content);
    }

    /**
     * The home page cards are RANGES, not cars. One card stands for every
     * Corolla the operator has, so putting a specific registration's photograph
     * on it would be the same misrepresentation in the opposite direction.
     */
    public function test_the_home_page_shows_the_class_photograph_not_a_particular_car(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $content = $this->get(route('home'))->assertSuccessful()->getContent();

        $this->assertStringContainsString(self::CLASS_PHOTO, $content);
        $this->assertStringNotContainsString(self::CAR_PHOTO, $content);
    }

    // --- The disclosure -----------------------------------------------------

    /**
     * Showing a class photograph is better than an empty frame. Showing one
     * SILENTLY, on the page carrying the price and the Reserve button, is the
     * misrepresentation this whole slice exists to remove.
     */
    public function test_the_vehicle_page_says_when_the_photographs_are_not_of_this_car(): void
    {
        [$branch, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class, ['make' => 'Toyota', 'model' => 'Corolla']);

        $this->get($this->vehicleUrl($vehicle))
            ->assertSuccessful()
            // The whole phrase, not just the tail. An earlier wording read
            // "Photographs show a Economy vehicle" — no single article can
            // serve both "Economy" and "SUV", and the seeded fleet has both.
            // This construction needs no article, and asserting it in full is
            // what would catch a reintroduced one.
            ->assertSee('another vehicle in the Economy range, not this exact car', escape: false);
    }

    /**
     * The page promises under "What is included" that a specific vehicle is
     * held once you reserve — which is true, because `place()` locks this row.
     * "Or similar" printed above that promise contradicted it, so it is gone
     * from this page entirely rather than made conditional.
     *
     * ⚠ If the operator decides §8.3 reassignment must be disclosed here, that
     * is a copy decision and this test is the thing to change deliberately
     * rather than the assertion to delete quietly.
     */
    public function test_the_vehicle_page_names_the_car_without_hedging(): void
    {
        [, $class] = $this->fleet();

        $vehicle = $this->vehicle($class, [
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'White',
        ]);

        $this->get($this->vehicleUrl($vehicle))
            ->assertSuccessful()
            ->assertDontSee('or similar')
            // The colour shows whether or not there is a photograph — with a
            // stand-in picture it is the only thing saying what will be
            // collected.
            ->assertSee('Toyota Corolla', escape: false)
            ->assertSee('white', escape: false);
    }

    public function test_the_vehicle_page_makes_no_such_disclaimer_about_its_own_photographs(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $this->get($this->vehicleUrl($vehicle))
            ->assertSuccessful()
            ->assertDontSee('not this exact car', escape: false);
    }

    /**
     * With nothing to disclaim there is nothing to say — the illustration is
     * self-evidently a drawing, and a warning beside it would be noise.
     */
    public function test_a_vehicle_with_no_photographs_renders_the_illustration_without_a_disclaimer(): void
    {
        [, $class] = $this->fleet();

        $vehicle = $this->vehicle($class);

        $response = $this->get($this->vehicleUrl($vehicle))->assertSuccessful();

        $this->assertStringContainsString('from-brand-50 to-brand-100', $response->getContent());
        $response->assertDontSee('not this exact car', escape: false);
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
        ], $attributes));

        return [$branch, $class];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function vehicle(VehicleClass $class, array $attributes = []): Vehicle
    {
        $branch = Branch::query()->where('operator_id', $class->operator_id)->firstOrFail();

        return Vehicle::factory()->create(array_merge([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ], $attributes));
    }

    private function searchUrl(Branch $branch): string
    {
        return route('search', [
            'branch' => $branch->getKey(),
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]);
    }

    private function vehicleUrl(Vehicle $vehicle): string
    {
        return route('vehicles.show', [
            'vehicle' => $vehicle->getKey(),
            'branch' => $vehicle->branch_id,
            'pickup' => '2026-09-20T09:00',
            'dropoff' => '2026-09-23T09:00',
        ]);
    }
}
