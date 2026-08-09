<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * WithoutModelEvents suppresses model hooks for the whole seeding run. That has
 * previously left slugs and other derived columns null on a fresh
 * migrate-and-seed, because the boot hook that would have filled them never
 * fired. It is safe here only because every seeder and factory below sets each
 * column explicitly rather than relying on a hook. Keep it that way.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            PaymentMethodSeeder::class,
            // Forgets the permission cache itself at both ends, because
            // WithoutModelEvents above suppresses the hooks that would
            // otherwise do it. See the seeder's own note.
            RolesAndPermissionsSeeder::class,
        ]);

        // Development only. The fleet is sample data; the staff accounts have
        // known passwords. Neither belongs anywhere but a local machine, and
        // DemoStaffSeeder refuses to run elsewhere even if this guard is ever
        // loosened. The fleet is seeded first so the staff can be posted to a
        // real branch.
        if (app()->environment('local')) {
            $this->call([
                DemoFleetSeeder::class,
                DemoStaffSeeder::class,
                // Fake account details, so a local checkout can offer bank
                // transfer and mobile money at all — since 2026-08-09 a method
                // with none is withheld from customers. PaymentMethodSeeder
                // above deliberately seeds none, because a production install
                // must not offer a method until real numbers are entered.
                DemoPaymentDetailsSeeder::class,
            ]);
        }
    }
}
