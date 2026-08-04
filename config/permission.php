<?php

declare(strict_types=1);

use Spatie\Permission\DefaultTeamResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Roles and Permissions
|--------------------------------------------------------------------------
|
| Written into the project rather than published with `vendor:publish`, so
| that the choices below are visible and reviewable rather than inherited
| silently from the package defaults.
|
| The authoritative list of permissions is NOT here — it is
| `App\Enums\StaffPermission`, which transcribes specification §12. This file
| only configures how the package stores and caches them.
|
*/

return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,

        /*
         * Teams are off, so no team model is required. See the note below.
         */
        'team' => null,

        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,        // defaults to role_id
        'permission_pivot_key' => null,  // defaults to permission_id

        'model_morph_key' => 'model_id',

        'team_foreign_key' => 'team_id',
    ],

    /*
     * Registers the package's check with Laravel's Gate, so `$user->can()`,
     * policies and `@can` all resolve permissions without further wiring.
     */

    'register_permission_check_method' => true,

    /*
     * Octane is not used; production is 20i shared hosting.
     */

    'register_octane_reset_listener' => false,

    /*
     * The package can fire events when a role or permission is attached or
     * detached. We do not listen to them: spec §12 requires an audit trail of
     * consequential actions, and that is written deliberately through
     * AuditLogger at the point the action is taken, where the actor, the
     * booking and the reason are all known. An event listener would see only
     * the pivot row.
     */

    'events_enabled' => false,

    /*
     * TEAMS
     *
     * Off. The platform's multi-tenancy seam is `operator_id` on the domain
     * tables, not this package's team feature — see ARCHITECTURE.md §8. Turning
     * teams on later requires a migration adding `team_id` to three tables, so
     * this is a decision, not a default left untouched.
     */

    'teams' => false,

    'team_resolver' => DefaultTeamResolver::class,

    'use_passport_client_credentials' => false,

    /*
     * Both left false: an exception that names the permission or role it wanted
     * tells an attacker the shape of the permission model. Staff see a generic
     * refusal; the specific reason goes to the log.
     */

    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,

    /*
     * Wildcard permissions would let `payments.*` stand in for the individual
     * grants. Deliberately off: spec §12 distinguishes confirming cash from
     * confirming a bank transfer precisely because a counter clerk may do one
     * and not the other, and a wildcard is exactly how that distinction gets
     * lost.
     */

    'enable_wildcard_permission' => false,

    'cache' => [

        /*
         * Permissions are read on every confirmation attempt, so they are
         * cached. The cache is flushed automatically whenever a role or
         * permission changes; the seeder also forgets it explicitly, because a
         * queue worker or a spawned test process holds its own copy.
         */

        'expiration_time' => DateInterval::createFromDateString('24 hours'),

        'key' => 'spatie.permission.cache',

        'store' => 'default',
    ],
];
