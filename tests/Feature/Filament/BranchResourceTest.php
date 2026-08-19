<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\StaffRole;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Models\Branch;
use App\Models\Operator;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where the operator trades from.
 *
 * The assertions that earn their place are about the opening hours. They are
 * nullable because spec §15.8 is the business's to answer, and the temptation
 * with a form is to make them required "for completeness" — which produces
 * exactly the invented figure the null exists to prevent, and has a customer
 * drive to a closed gate.
 *
 * The other half is the permission. This screen reuses `settings.manage` rather
 * than inventing `branches.manage`, so there is no new permission row and no
 * deployment step. A test pins that, because adding one later would be easy and
 * would silently break the panel on every existing database until the seeder
 * was re-run.
 */
final class BranchResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Who may ------------------------------------------------------------

    public function test_a_super_admin_may_manage_branches(): void
    {
        $this->actingAs($this->admin())
            ->get(BranchResource::getUrl('index'))
            ->assertSuccessful();
    }

    /**
     * Where the branch list sits in the permission model. §15.8 is a business
     * decision — the same category as the cancellation window — so it rides on
     * `settings.manage`, which a Branch Manager does not hold.
     *
     * A manager maintaining their OWN branch's hours is reasonable and is
     * deliberately not built: there is no branch scoping in this panel, so the
     * permission would let them edit every other branch too. Recorded in
     * BranchPolicy and due with the roles UI.
     */
    public function test_a_branch_manager_may_not(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->get(BranchResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_a_counter_clerk_may_not(): void
    {
        $this->actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->get(BranchResource::getUrl('index'))
            ->assertForbidden();
    }

    // --- Deletion is refused ------------------------------------------------

    public function test_there_is_no_delete_route(): void
    {
        $this->assertSame(
            ['index', 'create', 'edit'],
            array_keys(BranchResource::getPages()),
        );
    }

    /**
     * `vehicles` references `branches` with restrictOnDelete, and a booking's
     * collection point reads through it.
     */
    public function test_the_policy_refuses_deletion_outright(): void
    {
        $this->assertFalse($this->admin()->can('delete', $this->branch()));
    }

    // --- Creating -----------------------------------------------------------

    public function test_a_branch_can_be_added_without_opening_hours(): void
    {
        $operator = Operator::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(CreateBranch::class)
            ->fillForm([
                'operator_id' => $operator->getKey(),
                'name' => 'Ndola Branch',
                'code' => 'NLA',
                'city' => 'Ndola',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $branch = Branch::query()->where('code', 'NLA')->firstOrFail();

        // THE ONE THAT MATTERS. §15.8 is unanswered by default, and a form that
        // forced a time would put an invented one in front of customers.
        $this->assertNull($branch->opens_at);
        $this->assertNull($branch->closes_at);
        $this->assertFalse($branch->publishesHours());
        $this->assertNull($branch->openingHoursLabel());
    }

    public function test_two_branches_cannot_share_a_code(): void
    {
        $existing = $this->branch(['code' => 'LUN']);

        Livewire::actingAs($this->admin())
            ->test(CreateBranch::class)
            ->fillForm([
                'operator_id' => $existing->operator_id,
                'name' => 'Another Lusaka',
                'code' => 'LUN',
                'city' => 'Lusaka',
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    // --- Editing ------------------------------------------------------------

    public function test_publishing_hours_makes_them_readable(): void
    {
        $branch = $this->branch();

        Livewire::actingAs($this->admin())
            ->test(EditBranch::class, ['record' => $branch->getKey()])
            ->fillForm([
                'opens_at' => '08:00',
                'closes_at' => '17:00',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $branch->refresh();

        $this->assertTrue($branch->publishesHours());
        $this->assertSame('08:00 – 17:00', $branch->openingHoursLabel());
    }

    /**
     * Coherence, not policy. Staff may set any hours they like; what is refused
     * is a closing time before the opening one, which cannot be honoured.
     */
    public function test_closing_before_opening_is_refused(): void
    {
        $branch = $this->branch();

        Livewire::actingAs($this->admin())
            ->test(EditBranch::class, ['record' => $branch->getKey()])
            ->fillForm([
                'opens_at' => '17:00',
                'closes_at' => '08:00',
            ])
            ->call('save')
            ->assertHasFormErrors(['closes_at']);
    }

    /**
     * The code is what `DemoFleetSeeder` matches on with `firstOrCreate`.
     * Letting it change would make the next seeder run create a duplicate
     * branch instead of finding this one — and seeders run on every deploy.
     */
    public function test_the_code_cannot_be_changed_after_creation(): void
    {
        $branch = $this->branch(['code' => 'LUN']);

        Livewire::actingAs($this->admin())
            ->test(EditBranch::class, ['record' => $branch->getKey()])
            ->fillForm(['code' => 'CHANGED', 'city' => 'Kabwe'])
            ->call('save')
            ->assertHasNoFormErrors();

        $branch->refresh();

        $this->assertSame('LUN', $branch->code);
        // The rest of the form still saved, so the field is inert rather than
        // the whole screen being read-only.
        $this->assertSame('Kabwe', $branch->city);
    }

    // --- The worklist -------------------------------------------------------

    public function test_the_scope_finds_branches_missing_either_time(): void
    {
        $complete = $this->branch(['code' => 'AAA', 'opens_at' => '08:00', 'closes_at' => '17:00']);
        $neither = $this->branch(['code' => 'BBB']);
        // One time without the other says nothing useful while looking like an
        // answer, so it counts as unpublished. `closes_at` is stated explicitly
        // rather than left to the fixture, because THIS is the case the test
        // exists for and it should not depend on a default two files away.
        $halfOnly = $this->branch(['code' => 'CCC', 'opens_at' => '08:00', 'closes_at' => null]);

        $codes = Branch::query()->withoutPublishedHours()->pluck('code')->all();

        $this->assertNotContains($complete->code, $codes);
        $this->assertContains($neither->code, $codes);
        $this->assertContains($halfOnly->code, $codes);
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * A branch with NO published hours unless a test asks for them.
     *
     * `BranchFactory` publishes 08:00–17:00 by default, which is a sensible
     * default for a realistic branch and the wrong one here: this suite is
     * about the unanswered §15.8 case, and inheriting the factory's times made
     * the scope test assert that a fully-published branch was missing its
     * hours. Each test now opts in to the hours it actually means.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function branch(array $attributes = []): Branch
    {
        return Branch::factory()->create(array_merge([
            'opens_at' => null,
            'closes_at' => null,
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->withRole(StaffRole::SuperAdmin)->create();
    }
}
