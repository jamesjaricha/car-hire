<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a payment method is settled, which determines how it behaves rather than
 * simply what it is called.
 *
 * The distinction that matters operationally is whether money arrives at the
 * counter or somewhere the customer has to go and send it from. Everything
 * except cash requires the customer to act remotely and staff to verify it
 * afterwards — which is why the short-notice rule removes all of them.
 */
enum PaymentMethodType: string
{
    /** Handed over at the branch. */
    case OfflineCash = 'offline_cash';

    /** Sent to a bank account, verified against a statement. */
    case OfflineTransfer = 'offline_transfer';

    /** Sent to a merchant or till number, verified against a statement. */
    case OfflineMobileMoney = 'offline_mobile_money';

    /** Not enabled at MVP. The adapter interface exists so one can be added. */
    case CardGateway = 'card_gateway';

    /**
     * Whether money changes hands at the counter.
     *
     * Only these methods survive the short-notice rule: with pickup imminent
     * there is no time for a transfer to arrive and be verified, so the
     * customer simply pays when they arrive.
     */
    public function isSettledAtBranch(): bool
    {
        return $this === self::OfflineCash;
    }

    /**
     * Whether a staff member must confirm receipt by hand.
     *
     * True for everything at MVP. A card gateway would confirm itself, which is
     * the whole reason the adapter interface exists.
     */
    public function requiresManualConfirmation(): bool
    {
        return $this !== self::CardGateway;
    }

    public function label(): string
    {
        return match ($this) {
            self::OfflineCash => 'Cash at branch',
            self::OfflineTransfer => 'Bank transfer',
            self::OfflineMobileMoney => 'Mobile money',
            self::CardGateway => 'Card',
        };
    }
}
