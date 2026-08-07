<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Staff accounts for local development only.
 *
 * There is no other way to sign in to the panel: the platform has no
 * registration, and `php artisan make:filament-user` creates an account with no
 * role, which `User::canAccessPanel()` refuses by design.
 *
 * ONE OF EACH ROLE, ON PURPOSE
 *
 * A single super admin would make every screen look permitted, and the §12
 * permission matrix would go unexercised until somebody deployed it. Signing in
 * as the counter clerk is how you find out that a button you built is one they
 * cannot press.
 *
 * REFUSES TO RUN ANYWHERE BUT LOCAL
 *
 * These are known passwords. `DatabaseSeeder` already gates the call, and this
 * checks again rather than trusting it — a seeder that creates a super admin
 * with the password "password" must not be one command away from doing it on a
 * production database.
 */
final class DemoStaffSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'DemoStaffSeeder creates accounts with known passwords and runs only in the local environment.'
            );
        }

        $branch = Branch::query()->orderBy('id')->first();

        $this->staffMember('Super Admin', 'admin@carhire.test', StaffRole::SuperAdmin, null);
        $this->staffMember('Branch Manager', 'manager@carhire.test', StaffRole::BranchManager, $branch);
        $this->staffMember('Counter Clerk', 'clerk@carhire.test', StaffRole::CounterClerk, $branch);

        // A deliberate control. Signing in as this one must be refused by
        // canAccessPanel(), and having it seeded means that is something you
        // can check in a browser rather than only in a test.
        User::query()->firstOrCreate(
            ['email' => 'nobody@carhire.test'],
            ['name' => 'No Role', 'password' => Hash::make(self::PASSWORD)],
        );
    }

    private function staffMember(string $name, string $email, StaffRole $role, ?Branch $branch): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'branch_id' => $branch?->getKey(),
                'operator_id' => $branch?->operator_id,
            ],
        );

        // syncRoles rather than assignRole: re-seeding should leave the demo
        // accounts exactly as described here, not accumulate roles from
        // whatever was being tried out last week.
        $user->syncRoles([$role->value]);
    }
}
