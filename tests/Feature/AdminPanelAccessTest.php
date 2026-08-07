<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The front door.
 *
 * Filament's own contract file carries the warning this test exists to keep
 * true: without `FilamentUser`, "all authenticated users can access your panel
 * when APP_ENV is not local". Authentication is not authorisation — spec §12
 * grants permissions per action and per payment method, and a panel that shows
 * every booking and payment to anyone holding a password would make that work
 * beside the point.
 *
 * This checks only who may open the panel. What they may DO inside it is the
 * permissions' job, tested elsewhere.
 */
final class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_each_staff_role_may_open_the_panel(): void
    {
        foreach (StaffRole::cases() as $role) {
            $user = User::factory()->withRole($role)->create();

            $this->assertTrue(
                $user->canAccessPanel($this->panel()),
                "A {$role->label()} should be able to open the staff panel.",
            );
        }
    }

    /**
     * The case that matters. A `users` row created for any other reason — an
     * import, a test fixture, a half-finished account — must not be a way in.
     */
    public function test_a_user_with_no_role_is_refused(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    /**
     * A role invented in the admin panel is not a staff role. Recognising
     * anything with a row in `roles` would make the gate meaningless the first
     * time somebody created "Workshop" to group a few permissions.
     */
    public function test_a_role_outside_the_specification_is_not_a_way_in(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('workshop-foreman', 'web'));

        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_the_gate_fails_closed_on_an_unknown_panel(): void
    {
        // If a second panel is ever added — a customer portal, say — it must
        // not inherit staff access by default. Adding a panel should be a
        // deliberate grant, not something that happens by omission.
        $user = User::factory()->withRole(StaffRole::SuperAdmin)->create();

        // Built fresh rather than cloned: Panel::id() refuses to be set twice,
        // so a copy of the registered panel cannot be renamed.
        $customerPortal = Panel::make()->id('customers');

        $this->assertFalse($user->canAccessPanel($customerPortal));
    }

    // --- Over HTTP, which is what actually protects the data ---------------

    public function test_the_panel_redirects_an_anonymous_visitor_to_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_a_signed_in_user_with_no_role_is_refused_by_the_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_a_counter_clerk_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->get('/admin')
            ->assertSuccessful();
    }

    /**
     * The panel was generated as `james` at `/james` and renamed. This asserts
     * the rename actually took, rather than leaving a second route quietly
     * serving the same panel.
     */
    public function test_the_panel_is_served_at_admin(): void
    {
        $this->assertSame('admin', $this->panel()->getId());
        $this->assertSame('admin', $this->panel()->getPath());

        $this->get('/james')->assertNotFound();
    }

    private function panel(): Panel
    {
        return Filament::getPanel('admin');
    }
}
