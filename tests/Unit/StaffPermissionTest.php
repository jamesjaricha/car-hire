<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PaymentMethodCode;
use App\Enums\StaffPermission;
use Tests\TestCase;

final class StaffPermissionTest extends TestCase
{
    /**
     * Spec §12 grants confirmation per method. Mapping the wrong permission to
     * a method would either lock out a manager or let a clerk sign off a bank
     * transfer, so the mapping is asserted rather than assumed.
     */
    public function test_each_offline_method_maps_to_its_own_confirmation_permission(): void
    {
        $this->assertSame(
            StaffPermission::PaymentsConfirmCash,
            StaffPermission::toConfirm(PaymentMethodCode::Cash),
        );

        $this->assertSame(
            StaffPermission::PaymentsConfirmBankTransfer,
            StaffPermission::toConfirm(PaymentMethodCode::BankTransfer),
        );

        // Both mobile money providers share one permission: verifying either
        // means reading a statement, and the spec does not distinguish them.
        $this->assertSame(
            StaffPermission::PaymentsConfirmMobileMoney,
            StaffPermission::toConfirm(PaymentMethodCode::MtnMomo),
        );

        $this->assertSame(
            StaffPermission::PaymentsConfirmMobileMoney,
            StaffPermission::toConfirm(PaymentMethodCode::AirtelMoney),
        );
    }

    /**
     * Null means no permission exists that would allow it, not that no
     * permission is required. Callers must refuse.
     */
    public function test_card_methods_have_no_manual_confirmation_permission(): void
    {
        $this->assertNull(StaffPermission::toConfirm(PaymentMethodCode::DebitCard));
        $this->assertNull(StaffPermission::toConfirm(PaymentMethodCode::CreditCard));
    }

    public function test_the_permission_names_are_the_specification_strings(): void
    {
        // Transcribed from §12 by hand. If someone renames a case's value to
        // suit a naming convention, this is what stops it.
        //
        // SIX of these are NOT in §12 and are marked below — the count said
        // three while the list already marked five, so it is spelled out here
        // rather than trusted: payments.record-manual, bookings.cancel,
        // refunds.disburse, settings.manage, fleet.manage, fleet.manage-vehicles.
        //
        // Their names were chosen to read as though they were in §12 — same
        // dot-separated grouping — so that the matrix stays legible. That is
        // exactly why a hand-written list earns its place: an invented
        // permission is indistinguishable from a specified one at a glance, and
        // this fails loudly when one is added or moved.
        //
        // Order is the enum's declaration order, so a case inserted in the
        // middle fails here too rather than only changing the tail.
        $this->assertSame([
            'payments.view',
            'payments.confirm-cash',
            'payments.confirm-bank-transfer',
            'payments.confirm-mobile-money',
            'payments.record-manual',          // not in §12
            'payments.edit-manual-payment',
            'payments.extend-deadline',
            'bookings.reassign-vehicle',
            'bookings.override-short-notice',
            'bookings.cancel',                 // not in §12
            'kyc.verify',
            'security-deposit.collect',
            'security-deposit.refund',
            'refunds.request',
            'refunds.approve',
            'refunds.disburse',                // not in §12
            'cross-border.confirm',
            'payment-methods.manage',
            'settings.manage',                 // not in §12
            'fleet.manage',                    // not in §12
            'fleet.manage-vehicles',           // not in §12
        ], StaffPermission::names());
    }

    public function test_every_permission_has_a_label_and_a_group(): void
    {
        foreach (StaffPermission::cases() as $permission) {
            $this->assertNotSame('', $permission->label());
            $this->assertNotSame('', $permission->group());
        }
    }
}
