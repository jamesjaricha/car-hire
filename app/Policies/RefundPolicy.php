<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffPermission;
use App\Models\Refund;
use App\Models\User;

/**
 * Read-only, and enforced rather than merely intended. Same shape as
 * `BookingPolicy`, for the same reasons — see ARCHITECTURE §11.
 *
 * The stakes are higher here than on bookings. A refund's `amount` is computed
 * from spec §9 and deliberately not editable by anyone; an edit form would make
 * that computation a suggestion, and the person best placed to abuse it is the
 * one raising the request. `approved_by_user_id` is worse still: a form that can
 * write it is a form that can defeat §9.3's two-person rule from the browser.
 *
 * Every move a refund makes — approve, reject, disburse — is an explicit action
 * calling the service that owns it.
 */
final class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        // Everyone who may raise a refund may see them. Clerks raise them at the
        // counter and are the ones customers ring back to chase.
        return $user->hasPermissionTo(StaffPermission::RefundsRequest);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $user->hasPermissionTo(StaffPermission::RefundsRequest);
    }

    /**
     * Refunds are raised against a booking, by `RefundRequestService`, which
     * computes the amount from §9 and freezes it. A refund conjured from a form
     * would have a typed amount, no calculation behind it, and no booking lock.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * The amount is locked, the approver is subject to a two-person rule, and
     * the status is derived from a disbursement row. All three are exactly the
     * fields a generated edit form would offer.
     */
    public function update(User $user, Refund $refund): bool
    {
        return false;
    }

    /**
     * A refund is rejected, never deleted. Deleting one destroys the record of
     * money the business agreed it owed.
     */
    public function delete(User $user, Refund $refund): bool
    {
        return false;
    }

    public function restore(User $user, Refund $refund): bool
    {
        return false;
    }

    public function forceDelete(User $user, Refund $refund): bool
    {
        return false;
    }
}
