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
 * FIVE cases are NOT in §12, all added with the operator's agreement and all
 * marked where they are declared: `payments.record-manual`, `bookings.cancel`,
 * `refunds.disburse`, `settings.manage` and `fleet.manage`. Each covers an act
 * the specification simply does not name, and in each case the nearest existing
 * permission carried powers that did not belong with it.
 *
 * They arrived as the panel made each gap reachable: cancelling and disbursing
 * on 2026-08-08 with refunds, configuration and fleet pricing on 2026-08-09
 * with the screens that edit them. Before each was added, the real answer to
 * "who may do this" was whichever permission the calling screen happened to
 * check — which is to say, it was answered somewhere nobody would think to look.
 *
 * That pattern is worth expecting rather than being surprised by: §12 was
 * written as a list of things staff do to bookings and payments, so every
 * administrative screen tends to turn up one more gap.
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
    /**
     * Write down money that arrived, and attribute it to a booking.
     *
     * NOT IN SPEC §12. Added deliberately, with the operator's agreement.
     *
     * §12 lists no permission for recording a payment at all. The nearest was
     * `payments.edit-manual-payment`, and guarding recording with it forced a
     * choice between two wrong answers: give counter clerks the power to alter
     * payments already recorded, or stop the people actually standing at the
     * till from writing down money as it arrives. The second is worse — a
     * receipt that waits for a manager is a receipt written on a note beside
     * the till, which is where money goes missing.
     *
     * Splitting them keeps "record what arrived" and "change what was already
     * recorded" as separate powers, which is the distinction that matters when
     * someone asks later who could have altered a figure.
     */
    case PaymentsRecordManual = 'payments.record-manual';

    case PaymentsEditManualPayment = 'payments.edit-manual-payment';
    case PaymentsExtendDeadline = 'payments.extend-deadline';

    // --- Bookings ------------------------------------------------------------

    case BookingsReassignVehicle = 'bookings.reassign-vehicle';
    case BookingsOverrideShortNotice = 'bookings.override-short-notice';

    /**
     * End a booking by hand: cancel it, or mark it a no-show.
     *
     * NOT IN SPEC §12. Added deliberately, with the operator's agreement,
     * 2026-08-08.
     *
     * §12 lists fifteen permissions and none of them covers ending a hire,
     * which only became visible when refunds gave the panel a way to do it. The
     * first implementation therefore asserted nothing and let the calling
     * action's `refunds.request` stand in — which meant the real answer to "who
     * may cancel a booking" was "everybody", and it was discoverable only by
     * reading a service docblock.
     *
     * Held from Counter Clerk upwards. The clerk is the one facing a customer
     * who wants to cancel, and they can only start the process: the refund that
     * follows still needs a different, more senior person to approve it before
     * any money moves. Same reasoning as `bookings.override-short-notice`.
     */
    case BookingsCancel = 'bookings.cancel';

    // --- Counter operations --------------------------------------------------

    case KycVerify = 'kyc.verify';
    case SecurityDepositCollect = 'security-deposit.collect';
    case SecurityDepositRefund = 'security-deposit.refund';

    // --- Refunds -------------------------------------------------------------

    case RefundsRequest = 'refunds.request';
    case RefundsApprove = 'refunds.approve';

    /**
     * Physically hand an approved refund back, and record §9.3's proof.
     *
     * NOT IN SPEC §12. Added deliberately, with the operator's agreement,
     * 2026-08-08.
     *
     * §9.3 separates requesting from approving and says nothing about who pays.
     * Borrowing `refunds.approve` for it would have meant a counter clerk could
     * hand back a security deposit — which §12 explicitly permits — but not a
     * refund, across the same counter, to the same customer.
     *
     * Held from Counter Clerk upwards. The clerk executes a decision somebody
     * else made, at an amount neither of them can edit, and their name goes on
     * the disbursement reference.
     */
    case RefundsDisburse = 'refunds.disburse';

    // --- Everything else -----------------------------------------------------

    case CrossBorderConfirm = 'cross-border.confirm';
    case PaymentMethodsManage = 'payment-methods.manage';

    /**
     * Edit the operator-configurable values in the `settings` table.
     *
     * NOT IN SPEC §12. Added with the operator's agreement, 2026-08-09.
     *
     * §12 has no permission covering configuration, because when it was written
     * there was no screen to configure anything from. These values are not
     * preferences: the deposit percentage decides what every customer is asked
     * to pay, and the flat admin fee is subtracted from real refunds. Super
     * Admin only, on the same reasoning §12 uses for `payment-methods.manage`.
     */
    case SettingsManage = 'settings.manage';

    /**
     * Create and edit vehicle classes, and the pricing they carry.
     *
     * NOT IN SPEC §12. Added with the operator's agreement, 2026-08-09.
     *
     * Kept away from Branch Manager deliberately, even though a manager
     * maintaining their own vehicles is reasonable: class pricing is not local.
     * The daily rate, the damage waiver and the excess on a class apply to every
     * branch that holds one, so this screen changes what customers are charged
     * across the business. When vehicle-level CRUD arrives it can take a
     * narrower permission of its own.
     */
    case FleetManage = 'fleet.manage';

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
            self::PaymentsRecordManual => 'Record a payment received',
            self::PaymentsEditManualPayment => 'Edit a manually recorded payment',
            self::PaymentsExtendDeadline => 'Extend a payment deadline',
            self::BookingsReassignVehicle => 'Reassign a booking to another vehicle',
            self::BookingsOverrideShortNotice => 'Override the short-notice rule',
            self::BookingsCancel => 'Cancel a booking or mark it a no-show',
            self::KycVerify => 'Verify KYC documents',
            self::SecurityDepositCollect => 'Collect a security deposit',
            self::SecurityDepositRefund => 'Refund a security deposit',
            self::RefundsRequest => 'Request a refund',
            self::RefundsApprove => 'Approve a refund',
            self::RefundsDisburse => 'Hand an approved refund back to the customer',
            self::CrossBorderConfirm => 'Confirm cross-border paperwork',
            self::PaymentMethodsManage => 'Enable or disable payment methods',
            self::SettingsManage => 'Change platform settings',
            self::FleetManage => 'Manage vehicle classes and their pricing',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::PaymentsView,
            self::PaymentsConfirmCash,
            self::PaymentsConfirmBankTransfer,
            self::PaymentsConfirmMobileMoney,
            self::PaymentsRecordManual,
            self::PaymentsEditManualPayment,
            self::PaymentsExtendDeadline => 'payments',

            self::BookingsReassignVehicle,
            self::BookingsOverrideShortNotice,
            self::BookingsCancel => 'bookings',

            self::KycVerify => 'kyc',

            self::SecurityDepositCollect,
            self::SecurityDepositRefund => 'security-deposit',

            self::RefundsRequest,
            self::RefundsApprove,
            self::RefundsDisburse => 'refunds',

            self::CrossBorderConfirm => 'cross-border',

            self::PaymentMethodsManage,
            self::SettingsManage,
            self::FleetManage => 'administration',
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
