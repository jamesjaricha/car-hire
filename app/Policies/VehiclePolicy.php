<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffPermission;
use App\Models\User;
use App\Models\Vehicle;

/**
 * The physical fleet, and the second resource allowed real forms.
 *
 * ARCHITECTURE §11 draws the line at whether a service owns the writes. A
 * vehicle is genuinely CRUD-shaped: registration, make, model, where it lives
 * and whether it is on the road. No state machine, no locks, no ledger.
 *
 * WHY THIS IS A DIFFERENT PERMISSION FROM `fleet.manage`
 *
 * A vehicle class is a price list and applies across every branch that holds
 * one, so editing it is a business decision and stays Super Admin. A vehicle is
 * a car in a yard. The manager of the branch it sits at is the person who knows
 * it has gone in for repair, and requiring Head Office to record that is how a
 * fleet list drifts out of date. Hence `fleet.manage-vehicles`, Branch Manager
 * and above.
 *
 * The gap that opens is pricing: `vehicles.daily_rate` and
 * `vehicles.security_deposit_amount` are overrides, and setting one changes
 * what a customer pays. That is closed in `VehicleForm` rather than here, by
 * disabling both fields for anyone without `fleet.manage` — a policy answers
 * "may this person edit this record", not "may they edit this column".
 *
 * WHY DELETE IS REFUSED
 *
 * `vehicles` is referenced with `restrictOnDelete` from `vehicle_holds` and
 * `bookings`, so deleting a car that has ever been hired throws a raw
 * `QueryException` — a stack trace where a sentence belongs. And a booking's
 * history reads through its vehicle: somebody who hired ABC 1101 in March
 * should still have hired it next year.
 *
 * `status` is the off switch. `maintenance` takes a car off sale temporarily,
 * `retired` permanently, and `Vehicle::scopeBookable()` honours both.
 */
final class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManageVehicles);
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManageVehicles);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManageVehicles);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManageVehicles);
    }

    /**
     * Never. See the class docblock — retire it with `status` instead.
     */
    public function delete(User $user, Vehicle $vehicle): bool
    {
        return false;
    }

    public function restore(User $user, Vehicle $vehicle): bool
    {
        return false;
    }

    public function forceDelete(User $user, Vehicle $vehicle): bool
    {
        return false;
    }
}
