<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffPermission;
use App\Models\Branch;
use App\Models\User;

/**
 * Where the operator trades from.
 *
 * WHY `settings.manage` AND NOT A NEW PERMISSION
 *
 * Spec §15.8 is, word for word, "Branch list, operating hours, after-hours
 * pickup policy". That is a §15 business decision of exactly the kind
 * `settings.manage` already covers — not a fleet operation. A branch opening an
 * hour later is the same category of change as the cancellation notice window,
 * and it applies to every member of staff who works there.
 *
 * Reusing it is also worth something practical: `hasPermissionTo()` THROWS for a
 * permission missing from the table rather than returning false, so every new
 * `StaffPermission` case is a deployment step that breaks the panel until
 * `RolesAndPermissionsSeeder` is re-run. This screen needs none. Eight
 * documented departures from §12 stay eight.
 *
 * The argument for a narrower `branches.manage` at Branch Manager level is real
 * — a manager knows their own opening hours — but it is the wrong shape today:
 * there is no branch scoping in this panel (recorded in OPEN-ITEMS as a
 * decision), so a manager granted it could edit every other branch too. That is
 * a bigger change than a CRUD screen and belongs with the roles UI.
 *
 * WHY DELETE IS REFUSED
 *
 * `vehicles` references `branches` with `restrictOnDelete`, so deleting a branch
 * that has ever held a car is a raw `QueryException` — a stack trace where a
 * sentence belongs. Bookings also read their collection point through it, so a
 * hire collected from Livingstone in March should still say Livingstone next
 * year. `is_active` is the off switch: it removes the branch from the search
 * form and the locations page while leaving history intact.
 */
final class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::SettingsManage);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->hasPermissionTo(StaffPermission::SettingsManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::SettingsManage);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasPermissionTo(StaffPermission::SettingsManage);
    }

    /**
     * Never. See the class docblock — close it with `is_active` instead.
     */
    public function delete(User $user, Branch $branch): bool
    {
        return false;
    }

    public function restore(User $user, Branch $branch): bool
    {
        return false;
    }

    public function forceDelete(User $user, Branch $branch): bool
    {
        return false;
    }
}
