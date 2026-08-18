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
 * Photographs of the actual car, and an honest gap where there is none.
 *
 * WHY THIS EXISTS
 *
 * Photographs used to live only on the class, on the reasoning that an operator
 * with four Corollas photographs the Corolla once. That is true of his effort
 * and false of the customer's decision: this platform locks a SPECIFIC vehicle
 * row, so somebody is hiring a particular registration, and two cars in one
 * class differ in colour, age and condition. The operator's verdict on showing
 * a stand-in was that it "looks like a scam website".
 *
 * THE RULE, after the operator tightened it on 2026-08-18
 *
 * A class photograph appears on the HOME PAGE and nowhere else, because a home
 * page card stands for a range rather than a car. Everywhere a specific vehicle
 * is shown — search results, the class page's car list, the vehicle page — it is
 * that car's own photograph or the illustrated silhouette. Nothing in between.
 *
 * There was briefly a fallback to the class gallery with a caption admitting the
 * pictures were of a different car. It is gone: a page that does not show the
 * wrong thing beats a page that explains why it is showing the wrong thing.
 *
 * So most of what is asserted below is ABSENCE — that a class photograph cannot
 * reach a screen about one car, and that an unphotographed car says so by
 * drawing something obviously not a photograph.
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

    // --- What a vehicle's gallery resolves to -------------------------------

    public function test_a_vehicle_with_its_own_photographs_uses_them(): void
    {
        [, $class] = $this->fleet();

        $vehicle = $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $this->assertTrue($vehicle->hasOwnImages());
        $this->assertSame(self::CAR_PHOTO, $vehicle->primaryImagePath());
    }

    /**
     * THE ONE THAT MATTERS FOR TRUST. A car with no photograph of its own must
     * not quietly borrow one from a different car in the same class.
     */
    public function test_a_vehicle_never_borrows_its_classs_photographs(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class);

        $this->assertFalse($vehicle->hasOwnImages());
        $this->assertNull($vehicle->primaryImagePath());
        $this->assertSame([], $vehicle->imagePaths());
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
        $this->assertNull($vehicle->primaryImagePath());
    }

    public function test_a_vehicle_with_no_photographs_anywhere_has_none(): void
    {
        [, $class] = $this->fleet();

        $this->assertNull($this->vehicle($class)->primaryImagePath());
    }

    // --- The admin worklist -------------------------------------------------

    public function test_the_worklist_finds_vehicles_without_their_own_photographs(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $none = $this->vehicle($class, ['registration' => 'NON 001']);
        $emptied = $this->vehicle($class, ['registration' => 'EMP 001', 'image_paths' => []]);
        $photographed = $this->vehicle($class, [
            'registration' => 'OWN 001',
            'image_paths' => [self::CAR_PHOTO],
        ]);

        $awaiting = Vehicle::query()->withoutImages()->pluck('registration')->all();

        $this->assertContains($none->registration, $awaiting);
        $this->assertContains($emptied->registration, $awaiting);
        $this->assertNotContains($photographed->registration, $awaiting);
    }

    // --- Search results -----------------------------------------------------

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
     * The absence that matters: an unphotographed car draws the silhouette
     * rather than reaching for its class's picture.
     */
    public function test_an_unphotographed_car_shows_the_illustration_not_a_class_photograph(): void
    {
        [$branch, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $this->vehicle($class);

        $content = $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringNotContainsString(self::CLASS_PHOTO, $content);
        $this->assertStringContainsString('from-brand-50 to-brand-100', $content);
    }

    /**
     * "Or similar" is the hire trade's standard hedge and it was harmless while
     * every card carried a class photograph. Printed under a picture of THIS
     * car it contradicts the picture.
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
     * And the other direction, which is the half that keeps it honest: with no
     * photograph of the car, the hedge belongs there.
     */
    public function test_a_card_for_an_unphotographed_car_still_hedges(): void
    {
        [$branch, $class] = $this->fleet();

        $this->vehicle($class);

        $this->get($this->searchUrl($branch))
            ->assertSuccessful()
            ->assertSee('or similar');
    }

    // --- The class page -----------------------------------------------------

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
     * The class gallery strip used to sit above this list. Showing a range shot
     * immediately before showing the actual cars invited the customer to assume
     * it was one of them.
     */
    public function test_the_class_page_no_longer_shows_the_class_gallery(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $this->vehicle($class);

        $this->get(route('classes.show', ['slug' => $class->slug]))
            ->assertSuccessful()
            ->assertDontSee(self::CLASS_PHOTO, escape: false);
    }

    /**
     * The home page cards are RANGES, not cars — one card stands for every
     * Corolla the operator has — so this is the one place a class photograph is
     * honest, and it must keep working.
     */
    public function test_the_home_page_still_shows_the_class_photograph(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $content = $this->get(route('home'))->assertSuccessful()->getContent();

        $this->assertStringContainsString(self::CLASS_PHOTO, $content);
        $this->assertStringNotContainsString(self::CAR_PHOTO, $content);
    }

    // --- The vehicle page ---------------------------------------------------

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
            // The colour shows whether or not there is a photograph — with no
            // picture it is the only thing saying what will be collected.
            ->assertSee('Toyota Corolla', escape: false)
            ->assertSee('white', escape: false);
    }

    public function test_the_vehicle_page_never_shows_a_class_photograph(): void
    {
        [, $class] = $this->fleet(['image_paths' => [self::CLASS_PHOTO]]);

        $vehicle = $this->vehicle($class);

        $response = $this->get($this->vehicleUrl($vehicle))->assertSuccessful();

        $response->assertDontSee(self::CLASS_PHOTO, escape: false);
        $this->assertStringContainsString('from-brand-50 to-brand-100', $response->getContent());
    }

    // --- The gallery --------------------------------------------------------

    /**
     * EVERY uploaded photograph must be reachable.
     *
     * REGRESSION, reported by the operator as "no option to scroll the vehicle
     * images". The strip was built from `array_slice($images, 1, 4)` — so with
     * the six-image maximum the form allows, the sixth was rendered nowhere at
     * all. Uploaded, stored, and invisible. The thumbnails were also plain
     * `<img>` elements, so nothing could be enlarged.
     *
     * Asserted against `maxFiles(6)` deliberately: if that cap is ever raised,
     * this fails and the strip gets looked at rather than silently truncating
     * again.
     */
    public function test_every_photograph_appears_in_the_gallery_and_can_be_selected(): void
    {
        [, $class] = $this->fleet();

        $paths = [];

        for ($i = 1; $i <= 6; $i++) {
            $paths[] = "vehicles/car-{$i}.jpg";
        }

        $vehicle = $this->vehicle($class, ['image_paths' => $paths]);

        $content = $this->get($this->vehicleUrl($vehicle))
            ->assertSuccessful()
            ->getContent();

        foreach ($paths as $path) {
            $this->assertStringContainsString($path, $content);
        }

        // One control per photograph, the first marked as showing. Buttons
        // rather than divs, so the gallery is reachable by keyboard.
        //
        // ⚠ This count is why the container is `data-gallery-strip` and not
        // `data-gallery-thumbs`: the latter CONTAINS `data-gallery-thumb`, so
        // this returned 7 for six thumbnails and the assertion was measuring
        // the container as a seventh control. Keep the two names distinct
        // rather than relaxing the count.
        $this->assertSame(6, substr_count($content, 'data-gallery-thumb'));
        $this->assertStringContainsString('data-gallery-strip', $content);
        $this->assertStringContainsString('data-gallery-hero', $content);
        $this->assertStringContainsString('aria-current="true"', $content);
    }

    /**
     * One photograph is not a gallery, and a strip of one control to select the
     * thing already on screen is noise.
     */
    public function test_a_single_photograph_renders_no_thumbnail_strip(): void
    {
        [, $class] = $this->fleet();

        $vehicle = $this->vehicle($class, ['image_paths' => [self::CAR_PHOTO]]);

        $this->get($this->vehicleUrl($vehicle))
            ->assertSuccessful()
            ->assertDontSee('data-gallery-thumb', escape: false);
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
