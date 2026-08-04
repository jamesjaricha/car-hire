<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The payment methods the platform knows about. Spec §3.
 *
 * Card methods exist here so they can be displayed greyed out as "Coming Soon".
 * They are not enabled, and a request naming one is refused by the server —
 * greying out a button is decoration, not a control.
 */
enum PaymentMethodCode: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MtnMomo = 'mtn_momo';
    case AirtelMoney = 'airtel_money';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';

    public function type(): PaymentMethodType
    {
        return match ($this) {
            self::Cash => PaymentMethodType::OfflineCash,
            self::BankTransfer => PaymentMethodType::OfflineTransfer,
            self::MtnMomo, self::AirtelMoney => PaymentMethodType::OfflineMobileMoney,
            self::DebitCard, self::CreditCard => PaymentMethodType::CardGateway,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::MtnMomo => 'MTN Mobile Money',
            self::AirtelMoney => 'Airtel Money',
            self::DebitCard => 'Debit Card',
            self::CreditCard => 'Credit Card',
        };
    }

    /**
     * The configuration key controlling whether this method may be offered.
     *
     * Named after the environment variables in spec §4, but read through config
     * rather than env() directly — env() returns null once configuration is
     * cached, which on a production server would silently disable every method.
     */
    public function configKey(): string
    {
        return 'carhire.payment_methods.'.$this->value;
    }

    public function featureFlagName(): string
    {
        return 'PAYMENT_METHOD_'.strtoupper($this->value).'_ENABLED';
    }

    /** Default hold duration in hours, from spec §8.1. */
    public function defaultHoldDurationHours(): int
    {
        return match ($this) {
            self::Cash => 24,
            self::BankTransfer => 48,
            self::MtnMomo, self::AirtelMoney => 6,
            // Cards are not enabled; a gateway would confirm instantly anyway.
            self::DebitCard, self::CreditCard => 1,
        };
    }
}
