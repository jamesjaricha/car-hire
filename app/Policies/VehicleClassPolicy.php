<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffPermission;
use App\Models\User;
use App\Models\VehicleClass;

/**
 * The first resource in this panel that is allowed real forms.
 *
 * ARCHITECTURE §11 draws the line at whether a service owns the writes. A
 * booking's status belongs to `BookingStateMachine` and its money to
 * `PaymentConfirmationService`, so those get read-only screens. A vehicle class
 * is genuinely CRUD-shaped: it is a row of figures somebody types, with no
 * transitions, no locks and no ledger behind it.
 *
 * WHY DELETE IS STILL REFUSED
 *
 * `vehicle_classes` is referenced with `restrictOnDelete` from both `vehicles`
 * and `bookings`, so deleting a class that has ever been hired throws a raw
 * `QueryException` — a stack trace where the answer should have been a sentence.
 * More importantly, a booking's history reads through its class, and a customer
 * who hired an SUV in March should still have hired an SUV next year.
 *
 * `is_active` is the off switch. It stops a class being sold without pretending
 * it never existed.
 */
final class VehicleClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManage);
    }

    public function view(User $user, VehicleClass $class): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManage);
    }

    public function update(User $user, VehicleClass $class): bool
    {
        return $user->hasPermissionTo(StaffPermission::FleetManage);
    }

    /**
     * Never. See the class docblock — retire it with `is_active` instead.
     */
    public function delete(User $user, VehicleClass $class): bool
    {
        return false;
    }

    public function restore(User $user, VehicleClass $class): bool
    {
        return false;
    }

    public function forceDelete(User $user, VehicleClass $class): bool
    {
        return false;
    }
}
