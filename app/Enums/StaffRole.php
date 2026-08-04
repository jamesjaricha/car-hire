<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three staff roles of specification §12.
 *
 * The values are slugs rather than the display names, because they end up in
 * middleware strings and Blade directives where a space is a liability.
 * `label()` carries the wording the specification uses, and that is what staff
 * see.
 *
 * The grants below are the §12 matrix, read across each row. Two permissions in
 * the §12 list do not appear in that matrix at all — see `permissions()`.
 */
enum StaffRole: string
{
    case CounterClerk = 'counter-clerk';
    case BranchManager = 'branch-manager';
    case SuperAdmin = 'super-admin';

    public function label(): string
    {
        return match ($this) {
            self::CounterClerk => 'Counter Clerk',
            self::BranchManager => 'Branch Manager',
            self::SuperAdmin => 'Super Admin',
        };
    }

    /**
     * What this role may do.
     *
     * TWO JUDGEMENT CALLS, RECORDED HERE RATHER THAN BURIED
     *
     * `payments.edit-manual-payment` and `bookings.override-short-notice` are
     * in the §12 permission list but have no row in the §12 permission matrix,
     * so the specification does not say who holds them. Both are granted to
     * Branch Manager and above, on the reasoning that each is a correction to
     * or an exception from the automatic path — the same shape as extending a
     * deadline, which the matrix does place at Branch Manager. If the operator
     * wants either at the counter, it is a seeder change, not a redesign.
     *
     * THE CASH EXCEPTION
     *
     * §12 marks cash confirmation for a Counter Clerk as "Configurable per
     * branch", so the grant here is necessary but not sufficient: the clerk
     * holds the permission, and PaymentConfirmationService additionally
     * consults the `counter_clerk_may_confirm_cash` setting before allowing it.
     * The setting is open item §15.12 and is a placeholder, defaulting to
     * false. Branch Managers and above are not subject to that gate.
     *
     * @return list<StaffPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::CounterClerk => [
                StaffPermission::PaymentsView,
                // Held, but gated by the setting. See the note above.
                StaffPermission::PaymentsConfirmCash,
                StaffPermission::KycVerify,
                StaffPermission::SecurityDepositCollect,
                StaffPermission::SecurityDepositRefund,
                StaffPermission::RefundsRequest,
            ],

            self::BranchManager => [
                StaffPermission::PaymentsView,
                StaffPermission::PaymentsConfirmCash,
                StaffPermission::PaymentsConfirmBankTransfer,
                StaffPermission::PaymentsConfirmMobileMoney,
                StaffPermission::PaymentsEditManualPayment,
                StaffPermission::PaymentsExtendDeadline,
                StaffPermission::BookingsReassignVehicle,
                StaffPermission::BookingsOverrideShortNotice,
                StaffPermission::KycVerify,
                StaffPermission::SecurityDepositCollect,
                StaffPermission::SecurityDepositRefund,
                StaffPermission::RefundsRequest,
                StaffPermission::RefundsApprove,
                StaffPermission::CrossBorderConfirm,
            ],

            // Everything, including enabling and disabling payment methods,
            // which §12 gives to nobody else.
            self::SuperAdmin => StaffPermission::cases(),
        };
    }

    /**
     * Roles that are not subject to the per-branch cash confirmation setting.
     *
     * Kept here rather than inside the confirmation service so that the rule
     * lives beside the grants it qualifies.
     */
    public function isExemptFromCashConfirmationSetting(): bool
    {
        return $this !== self::CounterClerk;
    }
}
