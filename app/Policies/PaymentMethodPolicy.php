<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StaffPermission;
use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Spec §12 gives `payment-methods.manage` to Super Admin and to nobody else —
 * the only row in the matrix a Branch Manager does not hold.
 *
 * WHY THERE IS NO CREATE AND NO DELETE
 *
 * The six rows are the six cases of `PaymentMethodCode`, and each one is mapped
 * to an adapter class by `PaymentAdapterResolver`. A row created from a form
 * would have a code no enum case matches, no adapter, and no way to produce
 * instructions — and it would sit in the checkout list looking real.
 *
 * Deleting one is worse: `payments.payment_method_code` and the audit trail both
 * record the code as a string, so removing the row leaves historic payments
 * pointing at a method nothing can describe. A method is switched off with
 * `enabled`, which is what §4 intends.
 *
 * Adding a genuinely new provider means a new enum case, a new adapter and a
 * migration — deliberately, because each of those is a decision.
 */
final class PaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(StaffPermission::PaymentMethodsManage);
    }

    public function view(User $user, PaymentMethod $method): bool
    {
        return $user->hasPermissionTo(StaffPermission::PaymentMethodsManage);
    }

    public function update(User $user, PaymentMethod $method): bool
    {
        return $user->hasPermissionTo(StaffPermission::PaymentMethodsManage);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, PaymentMethod $method): bool
    {
        return false;
    }

    public function restore(User $user, PaymentMethod $method): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaymentMethod $method): bool
    {
        return false;
    }
}
