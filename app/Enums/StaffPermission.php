<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Specification §12, transcribed.
 *
 * The string values are the permission names stored in the `permissions` table
 * and are copied verbatim from the specification, so this file can be read side
 * by side with §12 and checked line for line. Do not tidy them into a different
 * naming convention — the spec is the contract.
 *
 * Named StaffPermission rather than Permission to keep it unambiguous against
 * `Spatie\Permission\Models\Permission`, which is the stored row. This enum is
 * the vocabulary; that model is the record.
 *
 * Adding a case here is not enough on its own. It must also be granted to the
 * roles that should hold it in RolesAndPermissionsSeeder, and the seeder has a
 * test asserting that every case is accounted for.
 */
enum StaffPermission: string
{
    // --- Payments ------------------------------------------------------------

    case PaymentsView = 'payments.view';
    case PaymentsConfirmCash = 'payments.confirm-cash';
    case PaymentsConfirmBankTransfer = 'payments.confirm-bank-transfer';
    case PaymentsConfirmMobileMoney = 'payments.confirm-mobile-money';
    case PaymentsEditManualPayment = 'payments.edit-manual-payment';
    case PaymentsExtendDeadline = 'payments.extend-deadline';

    // --- Bookings ------------------------------------------------------------

    case BookingsReassignVehicle = 'bookings.reassign-vehicle';
    case BookingsOverrideShortNotice = 'bookings.override-short-notice';

    // --- Counter operations --------------------------------------------------

    case KycVerify = 'kyc.verify';
    case SecurityDepositCollect = 'security-deposit.collect';
    case SecurityDepositRefund = 'security-deposit.refund';

    // --- Refunds -------------------------------------------------------------

    case RefundsRequest = 'refunds.request';
    case RefundsApprove = 'refunds.approve';

    // --- Everything else -----------------------------------------------------

    case CrossBorderConfirm = 'cross-border.confirm';
    case PaymentMethodsManage = 'payment-methods.manage';

    /**
     * The permission required to confirm a payment made by this method.
     *
     * Spec §12 grants confirmation per method, not per payment: a counter clerk
     * may take cash but may not sign off a bank transfer or a mobile money
     * receipt, because those require checking a statement they cannot see.
     * That distinction is the reason wildcard permissions are disabled.
     *
     * Returns null for the card methods. They are not enabled and cannot be
     * manually confirmed by anyone — a gateway would confirm its own payments.
     * A null here is not "no permission needed"; callers must refuse it.
     */
    public static function toConfirm(PaymentMethodCode $code): ?self
    {
        return match ($code) {
            PaymentMethodCode::Cash => self::PaymentsConfirmCash,
            PaymentMethodCode::BankTransfer => self::PaymentsConfirmBankTransfer,
            PaymentMethodCode::MtnMomo, PaymentMethodCode::AirtelMoney => self::PaymentsConfirmMobileMoney,
            PaymentMethodCode::DebitCard, PaymentMethodCode::CreditCard => null,
        };
    }

    /**
     * Human wording for the admin panel and for refusal messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::PaymentsView => 'View payments',
            self::PaymentsConfirmCash => 'Confirm a cash payment',
            self::PaymentsConfirmBankTransfer => 'Confirm a bank transfer',
            self::PaymentsConfirmMobileMoney => 'Confirm a mobile money payment',
            self::PaymentsEditManualPayment => 'Edit a manually recorded payment',
            self::PaymentsExtendDeadline => 'Extend a payment deadline',
            self::BookingsReassignVehicle => 'Reassign a booking to another vehicle',
            self::BookingsOverrideShortNotice => 'Override the short-notice rule',
            self::KycVerify => 'Verify KYC documents',
            self::SecurityDepositCollect => 'Collect a security deposit',
            self::SecurityDepositRefund => 'Refund a security deposit',
            self::RefundsRequest => 'Request a refund',
            self::RefundsApprove => 'Approve a refund',
            self::CrossBorderConfirm => 'Confirm cross-border paperwork',
            self::PaymentMethodsManage => 'Enable or disable payment methods',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::PaymentsView,
            self::PaymentsConfirmCash,
            self::PaymentsConfirmBankTransfer,
            self::PaymentsConfirmMobileMoney,
            self::PaymentsEditManualPayment,
            self::PaymentsExtendDeadline => 'payments',

            self::BookingsReassignVehicle,
            self::BookingsOverrideShortNotice => 'bookings',

            self::KycVerify => 'kyc',

            self::SecurityDepositCollect,
            self::SecurityDepositRefund => 'security-deposit',

            self::RefundsRequest,
            self::RefundsApprove => 'refunds',

            self::CrossBorderConfirm => 'cross-border',

            self::PaymentMethodsManage => 'administration',
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
