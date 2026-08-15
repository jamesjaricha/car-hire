<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\InsurancePriceMode;
use App\Enums\StaffRole;
use App\Filament\Resources\VehicleClasses\Pages\CreateVehicleClass;
use App\Filament\Resources\VehicleClasses\Pages\EditVehicleClass;
use App\Filament\Resources\VehicleClasses\VehicleClassResource;
use App\Models\Operator;
use App\Models\User;
use App\Models\VehicleClass;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The first resource in this panel allowed real forms.
 *
 * Two things are being checked: that a class can be created without inventing
 * the figures §15 leaves to the business, and that it still cannot be deleted.
 * The first is what stops "no deposit required" being published to customers;
 * the second is what stops a booking's history disappearing with its class.
 */
final class VehicleClassResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Who may ------------------------------------------------------------

    public function test_a_super_admin_may_manage_classes(): void
    {
        $this->actingAs($this->admin())
            ->get(VehicleClassResource::getUrl('index'))
            ->assertSuccessful();
    }

    /**
     * Kept away from Branch Manager deliberately: class pricing is not local.
     * A rate here applies to every branch holding that class.
     */
    public function test_a_branch_manager_may_not(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->get(VehicleClassResource::getUrl('index'))
            ->assertForbidden();
    }

    // --- Deletion is refused ------------------------------------------------

    public function test_the_policy_refuses_deletion(): void
    {
        $class = VehicleClass::factory()->create();

        // vehicle_classes is restrictOnDelete from vehicles AND bookings, so a
        // delete on a class with any history is a raw QueryException. More to
        // the point, a customer who hired an SUV in March should still have
        // hired an SUV next year.
        $this->assertFalse($this->admin()->can('delete', $class));
    }

    public function test_the_delete_route_does_not_exist(): void
    {
        $this->assertSame(
            ['index', 'create', 'edit'],
            array_keys(VehicleClassResource::getPages()),
        );
    }

    // --- Creating without inventing figures ---------------------------------

    /**
     * The §15 fields are not required, on purpose. Forcing a number would
     * produce the invented figure the null exists to prevent: somebody types a
     * zero to satisfy validation, and the class quietly becomes sellable with
     * "no security deposit" published to every customer who searches.
     */
    public function test_a_class_can_be_created_with_the_undecided_figures_left_empty(): void
    {
        $operator = Operator::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CreateVehicleClass::class)
            ->fillForm([
                'operator_id' => $operator->getKey(),
                'name' => 'Double Cab 4x4',
                'slug' => 'double-cab-4x4',
                'daily_rate' => '1450.00',
                'turnaround_buffer_minutes' => 120,
                'insurance_price_mode' => InsurancePriceMode::PerDay->value,
                'is_active' => true,
                // The three §15 answers, deliberately left blank.
                'insurance_price' => null,
                'insurance_excess_amount' => null,
                'security_deposit_amount' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $class = VehicleClass::query()->where('slug', 'double-cab-4x4')->firstOrFail();

        // Null, not zero. That distinction is the whole feature.
        $this->assertNull($class->security_deposit_amount);
        $this->assertNull($class->insurance_price);
        $this->assertNull($class->insurance_excess_amount);

        $this->assertFalse($class->isFullyPriced());
    }

    public function test_an_emptied_money_field_is_stored_as_undecided_rather_than_zero(): void
    {
        $class = VehicleClass::factory()->create(['security_deposit_amount' => '2500.00']);

        Livewire::actingAs($this->admin())
            ->test(EditVehicleClass::class, ['record' => $class->getKey()])
            ->fillForm(['security_deposit_amount' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        // An empty numeric input arrives as '', which the decimal cast would
        // store as 0.00 — indistinguishable from a deliberate zero.
        $this->assertNull($class->refresh()->security_deposit_amount);
    }

    public function test_a_decided_zero_is_stored_as_zero(): void
    {
        $class = VehicleClass::factory()->create(['security_deposit_amount' => null]);

        Livewire::actingAs($this->admin())
            ->test(EditVehicleClass::class, ['record' => $class->getKey()])
            ->fillForm(['security_deposit_amount' => '0'])
            ->call('save')
            ->assertHasNoFormErrors();

        // An operator who genuinely wants a zero-deposit class can say so, and
        // it counts as an answer rather than an omission.
        $this->assertSame('0.00', $class->refresh()->security_deposit_amount);
        $this->assertTrue($class->isFullyPriced());
    }

    public function test_pricing_can_be_completed_from_the_edit_screen(): void
    {
        $class = VehicleClass::factory()->create([
            'security_deposit_amount' => null,
            'insurance_price' => null,
            'insurance_excess_amount' => null,
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditVehicleClass::class, ['record' => $class->getKey()])
            ->fillForm([
                'security_deposit_amount' => '2500.00',
                'insurance_price' => '120.00',
                'insurance_excess_amount' => '5000.00',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($class->refresh()->isFullyPriced());
    }

    // --- The photograph queue -----------------------------------------------

    /**
     * A class with no photograph still sells — the customer card renders an
     * illustration — so this is a presentation gap rather than a fault. The
     * filter is the working queue for whoever is photographing the fleet.
     *
     * `image_paths` is JSON, and an emptied Filament upload writes `[]` rather
     * than null. Both mean "no photograph"; a scope testing only for null would
     * report an emptied gallery as populated, which is why both are seeded here.
     */
    public function test_the_without_images_scope_matches_null_and_empty_array(): void
    {
        $never = VehicleClass::factory()->create(['image_paths' => null]);
        $emptied = VehicleClass::factory()->create(['image_paths' => []]);
        $photographed = VehicleClass::factory()->create(['image_paths' => ['vehicle-classes/one.jpg']]);

        $matched = VehicleClass::query()->withoutImages()->pluck('id')->all();

        $this->assertContains($never->getKey(), $matched);
        $this->assertContains($emptied->getKey(), $matched);
        $this->assertNotContains($photographed->getKey(), $matched);
    }

    public function test_has_images_agrees_with_the_scope(): void
    {
        $emptied = VehicleClass::factory()->create(['image_paths' => []]);

        // The column and the filter must not disagree about the same row.
        $this->assertFalse($emptied->hasImages());
        $this->assertSame(0, count($emptied->imagePaths()));
    }

    private function admin(): User
    {
        return User::factory()->withRole(StaffRole::SuperAdmin)->create();
    }
}
