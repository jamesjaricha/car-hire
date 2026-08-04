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

        // Sample fleet for development only. Never seeded in production, where
        // the real fleet is entered through the admin panel.
        if (app()->environment('local')) {
            $this->call(DemoFleetSeeder::class);
        }
    }
}
