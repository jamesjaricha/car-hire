<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StaffUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_staff_member_belongs_to_a_branch_and_that_branchs_operator(): void
    {
        $branch = Branch::factory()->create();

        $user = User::factory()->atBranch($branch)->create();

        $this->assertSame($branch->getKey(), $user->branch_id);
        $this->assertSame($branch->operator_id, $user->operator_id);

        // Loaded explicitly: Model::shouldBeStrict() is on outside production
        // and refuses a lazy read.
        $user->load('branch');
        $this->assertTrue($user->branch->is($branch));
    }

    /**
     * Null is the Super Admin shape, not an unfilled field. Spec §12 scopes
     * several actions per branch, and someone who works across all of them
     * belongs to none.
     */
    public function test_a_staff_member_may_have_no_branch(): void
    {
        $admin = User::factory()->withRole(StaffRole::SuperAdmin)->create();

        $this->assertNull($admin->branch_id);
        $this->assertNull($admin->operator_id);
    }

    public function test_it_reports_roles_as_enum_cases(): void
    {
        $user = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->assertSame([StaffRole::BranchManager], $user->staffRoles());
        $this->assertTrue($user->hasStaffRole(StaffRole::BranchManager));
        $this->assertFalse($user->hasStaffRole(StaffRole::SuperAdmin));
    }

    /**
     * A role created outside the enum — by hand in the admin panel, say — must
     * not break a permission check. It is simply not one of ours.
     */
    public function test_a_role_that_is_not_one_of_ours_is_ignored_rather_than_fatal(): void
    {
        $user = User::factory()->withRole(StaffRole::CounterClerk)->create();
        $user->assignRole(Role::findOrCreate('workshop-foreman', 'web'));

        $this->assertSame([StaffRole::CounterClerk], $user->staffRoles());
    }

    /**
     * Spec §15.12. A counter clerk's cash confirmation is subject to the
     * setting; everyone above them is not.
     */
    public function test_only_a_counter_clerk_is_subject_to_the_cash_confirmation_setting(): void
    {
        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $admin = User::factory()->withRole(StaffRole::SuperAdmin)->create();

        $this->assertFalse($clerk->isExemptFromCashConfirmationSetting());
        $this->assertTrue($manager->isExemptFromCashConfirmationSetting());
        $this->assertTrue($admin->isExemptFromCashConfirmationSetting());
    }

    public function test_a_clerk_who_is_also_a_manager_is_exempt(): void
    {
        $user = User::factory()->withRole(StaffRole::CounterClerk)->create();
        $user->assignRole(StaffRole::BranchManager);

        $this->assertTrue($user->isExemptFromCashConfirmationSetting());
    }

    /**
     * Fails closed. Somebody with no recognised role does not get the benefit
     * of the doubt on a decision about accepting money.
     */
    public function test_a_user_with_no_role_is_not_exempt(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->staffRoles());
        $this->assertFalse($user->isExemptFromCashConfirmationSetting());
    }
}
