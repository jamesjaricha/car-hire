<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffPermission;
use App\Enums\StaffRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Specification §12, verified against the database rather than against the enum
 * that produced it.
 *
 * The expected permission lists below are transcribed from the specification by
 * hand and deliberately NOT derived from StaffRole::permissions(). Deriving
 * them would make this file agree with the code no matter what the code said,
 * which is not a test. If §12 and the seeder disagree, one of these fails.
 */
final class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spec §12: view payments, confirm cash (configurable per branch), verify
     * KYC, collect and refund the security deposit, request a refund.
     *
     * @var list<string>
     */
    private const COUNTER_CLERK = [
        'payments.view',
        'payments.confirm-cash',
        'kyc.verify',
        'security-deposit.collect',
        'security-deposit.refund',
        'refunds.request',
    ];

    /**
     * Spec §12: everything a clerk may do, plus transfers and mobile money,
     * deadline extension, vehicle reassignment, refund approval and
     * cross-border confirmation. Not payment method management.
     *
     * `payments.edit-manual-payment` and `bookings.override-short-notice` have
     * no row in the §12 matrix; both are placed here by decision, recorded in
     * StaffRole::permissions().
     *
     * @var list<string>
     */
    private const BRANCH_MANAGER = [
        'payments.view',
        'payments.confirm-cash',
        'payments.confirm-bank-transfer',
        'payments.confirm-mobile-money',
        'payments.edit-manual-payment',
        'payments.extend-deadline',
        'bookings.reassign-vehicle',
        'bookings.override-short-notice',
        'kyc.verify',
        'security-deposit.collect',
        'security-deposit.refund',
        'refunds.request',
        'refunds.approve',
        'cross-border.confirm',
    ];

    public function test_it_creates_every_permission_in_the_specification(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $stored = Permission::query()->pluck('name')->all();

        $this->assertEqualsCanonicalizing([
            'payments.view',
            'payments.confirm-cash',
            'payments.confirm-bank-transfer',
            'payments.confirm-mobile-money',
            'payments.edit-manual-payment',
            'payments.extend-deadline',
            'bookings.reassign-vehicle',
            'bookings.override-short-notice',
            'kyc.verify',
            'security-deposit.collect',
            'security-deposit.refund',
            'refunds.request',
            'refunds.approve',
            'cross-border.confirm',
            'payment-methods.manage',
        ], $stored);
    }

    public function test_it_creates_the_three_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['counter-clerk', 'branch-manager', 'super-admin'],
            Role::query()->pluck('name')->all(),
        );
    }

    public function test_a_counter_clerk_holds_exactly_the_specified_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            self::COUNTER_CLERK,
            $this->permissionsOf(StaffRole::CounterClerk),
        );
    }

    public function test_a_branch_manager_holds_exactly_the_specified_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            self::BRANCH_MANAGER,
            $this->permissionsOf(StaffRole::BranchManager),
        );
    }

    public function test_a_super_admin_holds_every_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            Permission::query()->pluck('name')->all(),
            $this->permissionsOf(StaffRole::SuperAdmin),
        );
    }

    /**
     * Spec §12: a counter clerk may not confirm a transfer or mobile money,
     * because verifying either means reading a statement they do not have.
     */
    public function test_a_counter_clerk_cannot_confirm_transfers_or_mobile_money(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();

        $this->assertFalse($clerk->hasPermissionTo(StaffPermission::PaymentsConfirmBankTransfer));
        $this->assertFalse($clerk->hasPermissionTo(StaffPermission::PaymentsConfirmMobileMoney));

        // And the one they do hold, so the assertions above are not passing
        // merely because nothing was granted at all.
        $this->assertTrue($clerk->hasPermissionTo(StaffPermission::PaymentsConfirmCash));
    }

    /**
     * Spec §12: enabling and disabling payment methods is Super Admin only.
     */
    public function test_only_a_super_admin_may_manage_payment_methods(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $admin = User::factory()->withRole(StaffRole::SuperAdmin)->create();

        $this->assertFalse($clerk->hasPermissionTo(StaffPermission::PaymentMethodsManage));
        $this->assertFalse($manager->hasPermissionTo(StaffPermission::PaymentMethodsManage));
        $this->assertTrue($admin->hasPermissionTo(StaffPermission::PaymentMethodsManage));
    }

    /**
     * A permission nobody holds is almost always an enum case that was added
     * without being granted, not a deliberate orphan.
     */
    public function test_every_permission_is_held_by_at_least_one_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $granted = array_unique(array_merge(
            $this->permissionsOf(StaffRole::CounterClerk),
            $this->permissionsOf(StaffRole::BranchManager),
            $this->permissionsOf(StaffRole::SuperAdmin),
        ));

        $this->assertEqualsCanonicalizing(StaffPermission::names(), array_values($granted));
    }

    public function test_it_can_be_run_twice_without_duplicating_anything(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('permissions', count(StaffPermission::cases()));
        $this->assertDatabaseCount('roles', count(StaffRole::cases()));

        $this->assertEqualsCanonicalizing(
            self::COUNTER_CLERK,
            $this->permissionsOf(StaffRole::CounterClerk),
        );
    }

    /**
     * The seeder is the authority for these three roles: a grant added by hand
     * is removed when it next runs. Asserted rather than merely documented,
     * because the alternative reading — that seeding is additive — would let
     * the live permission set drift away from the reviewed §12 matrix without
     * anything noticing.
     */
    public function test_reseeding_removes_a_permission_granted_outside_the_matrix(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $clerk = Role::findByName(StaffRole::CounterClerk->value, 'web');
        $clerk->givePermissionTo(StaffPermission::RefundsApprove->value);

        $this->assertContains('refunds.approve', $this->permissionsOf(StaffRole::CounterClerk));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertNotContains('refunds.approve', $this->permissionsOf(StaffRole::CounterClerk));
    }

    /**
     * The regression test for the WithoutModelEvents trap.
     *
     * DatabaseSeeder suppresses model events for the whole run, which includes
     * the hooks the permission package relies on to flush its cache. If the
     * seeder did not forget the cache by hand, permissions seeded through this
     * path would be invisible to the very next check — and the failure would
     * appear as a mystifying refusal in production, not here.
     */
    public function test_permissions_are_usable_immediately_after_the_full_database_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->assertTrue($manager->hasPermissionTo(StaffPermission::PaymentsConfirmMobileMoney));
        $this->assertTrue($manager->can(StaffPermission::PaymentsConfirmMobileMoney->value));
        $this->assertFalse($manager->can(StaffPermission::PaymentMethodsManage->value));
    }

    /**
     * @return list<string>
     */
    private function permissionsOf(StaffRole $role): array
    {
        return Role::findByName($role->value, 'web')
            ->permissions()
            ->pluck('name')
            ->all();
    }
}
