<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\StaffRole;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Branch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The physical fleet, and the second resource allowed real forms.
 *
 * The assertions that earn their place are about the two price overrides. They
 * are nullable — null means "inherit the class figure" — and a form that turned
 * an empty field into 0.00 would price a hire at nothing, silently, because a
 * booking at zero still looks like a booking.
 *
 * The other half is the permission seam: a Branch Manager may maintain the cars
 * at their branch but may not reprice one, or `fleet.manage` being Super Admin
 * would be undone through a side door.
 */
final class VehicleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Who may ------------------------------------------------------------

    public function test_a_branch_manager_may_reach_the_fleet(): void
    {
        $this->actingAs($this->manager())
            ->get(VehicleResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_a_super_admin_may_reach_the_fleet(): void
    {
        $this->actingAs($this->admin())
            ->get(VehicleResource::getUrl('index'))
            ->assertSuccessful();
    }

    /**
     * A clerk serves customers at a counter. Maintaining the fleet is not
     * their job, and §12 gives them nothing resembling it.
     */
    public function test_a_counter_clerk_may_not(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->get(VehicleResource::getUrl('index'))
            ->assertForbidden();
    }

    // --- Deletion is refused ------------------------------------------------

    /**
     * `vehicle_holds` and `bookings` both reference vehicles with
     * restrictOnDelete, so a delete on a hired car is a raw QueryException. And
     * a booking's history reads through its vehicle.
     */
    public function test_there_is_no_delete_route(): void
    {
        $this->assertSame(
            ['index', 'create', 'edit'],
            array_keys(VehicleResource::getPages()),
        );
    }

    public function test_the_policy_refuses_deletion_outright(): void
    {
        $vehicle = $this->vehicle();

        $this->assertFalse($this->admin()->can('delete', $vehicle));
        $this->assertFalse($this->manager()->can('delete', $vehicle));
    }

    // --- Creating -----------------------------------------------------------

    public function test_a_vehicle_can_be_added_and_inherits_its_class_pricing(): void
    {
        $branch = Branch::factory()->create();
        $class = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);

        Livewire::actingAs($this->admin())
            ->test(CreateVehicle::class)
            ->fillForm([
                'operator_id' => $branch->operator_id,
                'vehicle_class_id' => $class->getKey(),
                'branch_id' => $branch->getKey(),
                'registration' => 'ABC 9901',
                'make' => 'Toyota',
                'model' => 'Land Cruiser',
                'year' => 2023,
                'colour' => 'white',
                'seats' => 7,
                'transmission' => 'manual',
                'fuel_type' => 'diesel',
                'status' => VehicleStatus::Available->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vehicle = Vehicle::query()->where('registration', 'ABC 9901')->firstOrFail();

        // THE ONE THAT MATTERS. Empty must reach the database as null, not as
        // 0.00 — PricingService reads a non-null override and does not sanity
        // check it against the class, so a zero here is a free hire.
        $this->assertNull($vehicle->daily_rate);
        $this->assertNull($vehicle->security_deposit_amount);
    }

    public function test_two_vehicles_cannot_share_a_registration(): void
    {
        $existing = $this->vehicle(['registration' => 'ABC 1234']);

        Livewire::actingAs($this->admin())
            ->test(CreateVehicle::class)
            ->fillForm([
                'operator_id' => $existing->operator_id,
                'vehicle_class_id' => $existing->vehicle_class_id,
                'branch_id' => $existing->branch_id,
                'registration' => 'ABC 1234',
                'make' => 'Mazda',
                'model' => 'Demio',
                'status' => VehicleStatus::Available->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['registration']);
    }

    // --- Editing, and the pricing seam --------------------------------------

    public function test_a_super_admin_may_set_a_rate_override(): void
    {
        $vehicle = $this->vehicle();

        Livewire::actingAs($this->admin())
            ->test(EditVehicle::class, ['record' => $vehicle->getKey()])
            ->fillForm(['daily_rate' => '3200.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('3200.00', $vehicle->refresh()->daily_rate);
    }

    /**
     * Clearing an override must restore inheritance rather than write a zero.
     */
    public function test_clearing_an_override_restores_inheritance(): void
    {
        $vehicle = $this->vehicle(['daily_rate' => '3200.00']);

        Livewire::actingAs($this->admin())
            ->test(EditVehicle::class, ['record' => $vehicle->getKey()])
            ->fillForm(['daily_rate' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($vehicle->refresh()->daily_rate);
    }

    /**
     * THE SEAM. A manager may edit the vehicle, and their save must leave an
     * override exactly as it was — not change it, and not clear it.
     *
     * If the disabled fields were ever dehydrated, a manager pressing Save on an
     * unrelated change would write null over a Super Admin's figure: a price
     * change made by somebody who is not allowed to make one, with nothing on
     * screen showing it happened.
     */
    public function test_a_branch_manager_saving_a_vehicle_cannot_disturb_its_price(): void
    {
        $vehicle = $this->vehicle([
            'daily_rate' => '3200.00',
            'security_deposit_amount' => '9000.00',
        ]);

        Livewire::actingAs($this->manager())
            ->test(EditVehicle::class, ['record' => $vehicle->getKey()])
            ->fillForm(['colour' => 'green'])
            ->call('save')
            ->assertHasNoFormErrors();

        $vehicle->refresh();

        $this->assertSame('green', $vehicle->colour);
        $this->assertSame('3200.00', $vehicle->daily_rate);
        $this->assertSame('9000.00', $vehicle->security_deposit_amount);
    }

    // --- Photographs --------------------------------------------------------

    /**
     * Photographs must survive an unrelated save by the person most likely to
     * make one.
     *
     * The same failure shape as the two price overrides, reached differently: a
     * multi-file upload whose state is not carried through the form would
     * dehydrate as empty, and a Branch Manager changing a colour would silently
     * delete the fleet photographer's work. Nothing on screen would say so, and
     * the site would quietly fall back to class pictures — which looks fine,
     * which is exactly why it needs a test rather than an eye.
     *
     * ⚠ THE FAKED DISK IS LOAD-BEARING, NOT TIDINESS. `BaseFileUpload::
     * hydrateFiles()` filters the stored state through `$disk->exists($file)`,
     * so a path pointing at no actual file is dropped on hydration and then
     * dehydrated as empty. Writing bare strings into `image_paths` therefore
     * fails this test while the code is entirely correct — which is how it
     * failed the first time it was written. Real files, or this proves nothing.
     *
     * Worth knowing for production too: it means a vehicle whose photographs
     * have vanished from disk loses its paths the next time anybody saves it.
     * That is Filament's behaviour and it is defensible — a path to a missing
     * file is useless — but it would also be the symptom if `storage:link` were
     * ever broken on the server.
     */
    public function test_a_managers_unrelated_save_does_not_wipe_the_photographs(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('vehicles/abc-1234-front.jpg', 'not really a jpeg');
        Storage::disk('public')->put('vehicles/abc-1234-interior.jpg', 'not really a jpeg');

        $vehicle = $this->vehicle([
            'image_paths' => ['vehicles/abc-1234-front.jpg', 'vehicles/abc-1234-interior.jpg'],
        ]);

        Livewire::actingAs($this->manager())
            ->test(EditVehicle::class, ['record' => $vehicle->getKey()])
            ->fillForm(['colour' => 'green'])
            ->call('save')
            ->assertHasNoFormErrors();

        $vehicle->refresh();

        $this->assertSame('green', $vehicle->colour);
        $this->assertSame(
            ['vehicles/abc-1234-front.jpg', 'vehicles/abc-1234-interior.jpg'],
            $vehicle->ownImagePaths(),
        );
    }

    /**
     * Photographing a car is NOT a pricing power, so it sits under
     * `fleet.manage-vehicles` rather than `fleet.manage` — the manager of the
     * branch the car is parked at is the only person who can actually go and
     * photograph it. No new permission row was added for this, and this test is
     * what would catch it if one ever were.
     */
    public function test_a_branch_manager_may_reach_the_photographs_field(): void
    {
        $vehicle = $this->vehicle();

        Livewire::actingAs($this->manager())
            ->test(EditVehicle::class, ['record' => $vehicle->getKey()])
            ->assertFormFieldExists('image_paths');
    }

    /**
     * Taking a car off the road is the delete replacement, and it must actually
     * remove it from sale.
     */
    public function test_marking_a_vehicle_off_the_road_removes_it_from_the_bookable_fleet(): void
    {
        $vehicle = $this->vehicle();

        $this->assertTrue(Vehicle::query()->bookable()->whereKey($vehicle->getKey())->exists());

        Livewire::actingAs($this->manager())
            ->test(EditVehicle::class, ['record' => $vehicle->getKey()])
            ->fillForm(['status' => VehicleStatus::Maintenance->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(Vehicle::query()->bookable()->whereKey($vehicle->getKey())->exists());
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function vehicle(array $attributes = []): Vehicle
    {
        $branch = Branch::factory()->create();
        $class = VehicleClass::factory()->create(['operator_id' => $branch->operator_id]);

        return Vehicle::factory()->create(array_merge([
            'operator_id' => $branch->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->withRole(StaffRole::SuperAdmin)->create();
    }

    private function manager(): User
    {
        return User::factory()->withRole(StaffRole::BranchManager)->create();
    }
}
