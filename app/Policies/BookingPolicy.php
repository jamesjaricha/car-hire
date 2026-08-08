<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffPermission;
use App\Models\Booking;
use App\Models\User;

/**
 * Read-only, and enforced rather than merely intended.
 *
 * Filament reads policies to decide which buttons to render, so returning false
 * from `create`, `update` and `delete` both hides those controls and refuses
 * them if reached another way. That matters more than it looks: ARCHITECTURE
 * §11 says bookings get no forms, and a rule kept only in a docblock is one
 * `make:filament-resource` away from being broken by somebody in a hurry. This
 * makes the framework itself say no.
 *
 * A booking changes through `BookingStateMachine`, `PaymentConfirmationService`
 * and the other services that own its transitions — never through a form post.
 */
final class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::PaymentsView);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->hasPermissionTo(StaffPermission::PaymentsView);
    }

    /**
     * Bookings are created by customers at checkout, through
     * `BookingCreationService`, which places the vehicle hold in the same
     * transaction. A booking conjured from an admin form would have no hold,
     * no reference from the counter, and no payment record.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Every field worth changing has a service that owns it: the status belongs
     * to the state machine, the money to the confirmation service, the deadline
     * to the extension service. A form here would write past all three.
     */
    public function update(User $user, Booking $booking): bool
    {
        return false;
    }

    /**
     * Bookings are cancelled, never deleted. A deleted booking takes its
     * payments, its audit trail and its customer's history with it.
     */
    public function delete(User $user, Booking $booking): bool
    {
        return false;
    }

    public function restore(User $user, Booking $booking): bool
    {
        return false;
    }

    public function forceDelete(User $user, Booking $booking): bool
    {
        return false;
    }
}
