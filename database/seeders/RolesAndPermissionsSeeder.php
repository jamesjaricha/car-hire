<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffPermission;
use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The specification §12 permission matrix, as data.
 *
 * THIS SEEDER IS THE AUTHORITY FOR THESE THREE ROLES
 *
 * Grants are applied with `syncPermissions()`, not additively. Re-running the
 * seeder therefore restores the §12 matrix exactly, and a permission granted to
 * one of these three roles by hand will be removed the next time it runs. That
 * is deliberate: this is the file a reviewer reads to answer "who can confirm a
 * payment", and it should not be possible for the answer to be yes in the
 * database and no here.
 *
 * An operator who needs a different combination gets a NEW role. They do not
 * get an edited Counter Clerk.
 *
 * WHY THIS DOES NOT USE Permission::findOrCreate() OR Role::findOrCreate()
 *
 * Those are the idiomatic calls, and they are wrong here. Both read through
 * PermissionRegistrar, which holds the permission collection in memory and
 * reloads it only when told to. It is normally told to by model events on
 * Permission and Role — and `DatabaseSeeder` runs with `WithoutModelEvents`,
 * which suppresses precisely those events for the whole run.
 *
 * The failure that causes is quiet and confusing. The first findOrCreate()
 * loads the collection while the table is still empty; an empty collection is
 * still a truthy object, so the registrar never reloads it. All fifteen
 * permissions are then created against a stale view, and the first
 * syncPermissions() throws PermissionDoesNotExist for a permission that is
 * sitting in the table.
 *
 * So every read here is a direct query, and the grants are passed as model
 * instances rather than names — `collectPermissions()` returns those untouched
 * instead of resolving them through the cache. Flushing the cache between the
 * two loops would also work, but only until someone adds a third loop. This
 * way there is no ordering rule to remember.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Anything already resolved in this process is about to be wrong.
        $registrar->forgetCachedPermissions();

        foreach (StaffPermission::cases() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => self::GUARD,
            ]);
        }

        $permissions = $this->storedPermissions();

        foreach (StaffRole::cases() as $staffRole) {
            $role = Role::query()->firstOrCreate([
                'name' => $staffRole->value,
                'guard_name' => self::GUARD,
            ]);

            $role->syncPermissions($this->grantsFor($staffRole, $permissions));
        }

        // And anything resolved during the run is now stale in its turn.
        $registrar->forgetCachedPermissions();
    }

    /**
     * Every stored permission, keyed by name.
     *
     * @return Collection<string, Permission>
     */
    private function storedPermissions(): Collection
    {
        return Permission::query()
            ->where('guard_name', self::GUARD)
            ->get()
            ->keyBy('name');
    }

    /**
     * The role's grants, as stored models.
     *
     * @param  Collection<string, Permission>  $permissions
     * @return list<Permission>
     */
    private function grantsFor(StaffRole $role, Collection $permissions): array
    {
        return array_map(
            // The permission loop above created every case, so a miss here is
            // not a missing row — it is a permission that was deleted from the
            // database by hand, and failing loudly is the right answer.
            fn (StaffPermission $permission): Permission => $permissions->get($permission->value)
                ?? throw new RuntimeException(
                    "Permission [{$permission->value}] is missing after seeding. The permissions table has been modified outside this seeder."
                ),
            $role->permissions(),
        );
    }
}
