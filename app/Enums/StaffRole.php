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
     * WHERE THIS GOES BEYOND §12, AND WHY
     *
     * Three decisions here are not in the specification. All were settled with
     * the operator on 2026-08-05 rather than left as assumptions.
     *
     * `payments.edit-manual-payment` and `bookings.override-short-notice` are in
     * the §12 permission list but have no row in the §12 matrix, so the
     * specification never says who holds them. Editing a payment already
     * recorded stays at Branch Manager and above — it changes a figure somebody
     * relied on. Overriding the short-notice rule went the other way, to the
     * counter: the clerk is the one facing a customer three hours before
     * pickup, and making them fetch a manager is the friction the override
     * exists to remove.
     *
     * `payments.record-manual` is not in §12 at all. See its declaration in
     * StaffPermission for why it had to be invented rather than borrowed.
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
                // The person at the till is the person who sees money arrive.
                // Making them wait for a manager to write it down is how a
                // receipt ends up on a note beside the till instead.
                StaffPermission::PaymentsRecordManual,
                // The clerk is the one facing a customer standing in front of
                // them three hours before pickup. Sending them away to find a
                // manager is the friction spec §8.2's override exists to avoid.
                StaffPermission::BookingsOverrideShortNotice,
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
                StaffPermission::PaymentsRecordManual,
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
