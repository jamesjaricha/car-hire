<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AvailabilityServiceContract;
use App\Contracts\PricingServiceContract;
use App\DataTransferObjects\DateRange;
use App\Exceptions\VehicleClassNotPricedException;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §15's per-class figures, and what happens when nobody has decided them.
 *
 * THE FAILURE THIS PREVENTS
 *
 * Until 2026-08-09 these columns were `NOT NULL DEFAULT 0`, so an undecided
 * security deposit and a deliberate zero were the same value. That is not a
 * tidiness problem. Spec §6 requires the deposit to appear in search results,
 * at checkout, in the confirmation email and in the T&Cs, and says it "must
 * never first appear at the counter" — so a class left at the default published
 * "no deposit required" to every customer who looked, and then somebody was
 * asked for K2,500 as they collected the keys.
 *
 * Null now means undecided, and an undecided class cannot reach a customer.
 */
final class VehicleClassPricingDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(SettingsSeeder::class);
    }

    // --- The model's own view ----------------------------------------------

    public function test_a_class_with_every_figure_decided_is_fully_priced(): void
    {
        $class = VehicleClass::factory()->create();

        $this->assertTrue($class->isFullyPriced());
        $this->assertSame([], $class->missingPricingDecisions());
    }

    public function test_a_decided_zero_is_not_a_missing_decision(): void
    {
        // The whole point of the nullable columns: an operator who genuinely
        // wants a zero-deposit class can say so, and it counts as an answer.
        $class = VehicleClass::factory()->create([
            'security_deposit_amount' => '0.00',
            'insurance_price' => '0.00',
            'insurance_excess_amount' => '0.00',
        ]);

        $this->assertTrue($class->isFullyPriced());
    }

    public function test_it_names_each_undecided_figure(): void
    {
        $class = VehicleClass::factory()->create([
            'security_deposit_amount' => null,
            'insurance_excess_amount' => null,
        ]);

        // Named rather than counted: the panel has to tell somebody what to go
        // and enter, and "this class is incomplete" is not enough to act on.
        $this->assertCount(2, $class->missingPricingDecisions());
        $this->assertFalse($class->isFullyPriced());
    }

    public function test_the_scopes_split_the_fleet(): void
    {
        $priced = VehicleClass::factory()->create();
        $unpriced = VehicleClass::factory()->create(['insurance_price' => null]);

        $this->assertSame(
            [$priced->getKey()],
            VehicleClass::query()->fullyPriced()->pluck('id')->all(),
        );

        $this->assertSame(
            [$unpriced->getKey()],
            VehicleClass::query()->awaitingPricingDecisions()->pluck('id')->all(),
        );
    }

    // --- Pricing refuses rather than guesses --------------------------------

    public function test_pricing_refuses_a_class_with_no_decided_security_deposit(): void
    {
        $vehicle = $this->vehicleInClass(['security_deposit_amount' => null]);

        $this->expectException(VehicleClassNotPricedException::class);

        app(PricingServiceContract::class)->securityDepositFor($vehicle);
    }

    public function test_pricing_refuses_a_class_with_no_decided_excess(): void
    {
        $vehicle = $this->vehicleInClass(['insurance_excess_amount' => null]);

        $this->expectException(VehicleClassNotPricedException::class);

        app(PricingServiceContract::class)->insuranceExcessFor($vehicle);
    }

    public function test_pricing_refuses_a_class_with_no_decided_waiver_price(): void
    {
        $vehicle = $this->vehicleInClass(['insurance_price' => null]);

        $this->expectException(VehicleClassNotPricedException::class);

        app(PricingServiceContract::class)->insuranceTotal($vehicle, $this->range());
    }

    /**
     * A vehicle carrying its own deposit is sellable even while its class has
     * not decided one — the figure shown to the customer is the vehicle's, and
     * it is a real decision.
     */
    public function test_a_vehicle_level_deposit_override_survives_an_undecided_class(): void
    {
        $vehicle = $this->vehicleInClass(
            ['security_deposit_amount' => null],
            ['security_deposit_amount' => '1800.00'],
        );

        $this->assertSame('1800.00', app(PricingServiceContract::class)->securityDepositFor($vehicle));
    }

    /**
     * The daily rate has never been nullable, so an undecided deposit does not
     * stop the hire total being calculated. Worth pinning: the refusal must be
     * specific to the figures §15 leaves open, not a blanket one.
     */
    public function test_an_undecided_deposit_does_not_break_the_hire_total(): void
    {
        $vehicle = $this->vehicleInClass(['security_deposit_amount' => null]);

        $this->assertSame(
            '2550.00',
            app(PricingServiceContract::class)->hireTotal($vehicle, $this->range()),
        );
    }

    // --- Search withholds the class -----------------------------------------

    /**
     * The protection that actually matters. The exception is a backstop; this
     * is what stops a customer ever seeing an unpriced class.
     */
    public function test_an_unpriced_class_is_withheld_from_search_results(): void
    {
        $branch = Branch::factory()->create();

        $sellable = $this->vehicleAtBranch($branch);
        $this->vehicleAtBranch($branch, ['insurance_excess_amount' => null]);

        $available = app(AvailabilityServiceContract::class)
            ->availableVehicles($branch, $this->range());

        $this->assertSame([$sellable->getKey()], $available->pluck('id')->all());
    }

    public function test_a_single_vehicle_check_agrees_with_the_search(): void
    {
        $vehicle = $this->vehicleInClass(['security_deposit_amount' => null]);

        // The two must never disagree about whether something is sellable, or
        // a vehicle absent from search could still be booked directly.
        $this->assertFalse(
            app(AvailabilityServiceContract::class)->isVehicleAvailable($vehicle, $this->range())
        );
    }

    public function test_a_fully_priced_class_is_offered_as_normal(): void
    {
        $branch = Branch::factory()->create();
        $vehicle = $this->vehicleAtBranch($branch);

        $available = app(AvailabilityServiceContract::class)
            ->availableVehicles($branch, $this->range());

        $this->assertSame([$vehicle->getKey()], $available->pluck('id')->all());
    }

    // --- Fixtures -----------------------------------------------------------

    private function range(): DateRange
    {
        return new DateRange(
            $this->now->addDays(7)->setTime(9, 0),
            $this->now->addDays(10)->setTime(9, 0),
        );
    }

    /**
     * @param  array<string, mixed>  $classAttributes
     * @param  array<string, mixed>  $vehicleAttributes
     */
    private function vehicleInClass(array $classAttributes, array $vehicleAttributes = []): Vehicle
    {
        $class = VehicleClass::factory()->create($classAttributes);

        return Vehicle::factory()->create(array_merge([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
        ], $vehicleAttributes));
    }

    /**
     * @param  array<string, mixed>  $classAttributes
     */
    private function vehicleAtBranch(Branch $branch, array $classAttributes = []): Vehicle
    {
        $class = VehicleClass::factory()->create(array_merge(
            ['operator_id' => $branch->operator_id],
            $classAttributes,
        ));

        return Vehicle::factory()->create([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);
    }
}
