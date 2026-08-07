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
        $this->assertSame([
            'payments.view',
            'payments.confirm-cash',
            'payments.confirm-bank-transfer',
            'payments.confirm-mobile-money',
            'payments.record-manual',
            'payments.edit-manual-payment',
            'payments.extend-deadline',
            'bookings.reassign-vehicle',
            'bookings.override-short-notice',
            'kyc.verify',
            'security-deposit.collect',
            'security-deposit.refund',
            'refunds.request',
            'refunds.approve',
            'cross-border.confirm',
            'payment-methods.manage',
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
