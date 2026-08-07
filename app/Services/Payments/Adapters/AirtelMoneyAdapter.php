<?php

declare(strict_types=1);

namespace App\Services\Payments\Adapters;

use App\Enums\PaymentMethodCode;

/**
 * Airtel Money, paid to a merchant number.
 *
 * Behaves identically to MTN at MVP — both are manual verification against a
 * statement (spec §3.1) — but it is a separate class rather than a shared
 * "mobile money" one, because the two are separate businesses with separate
 * merchant numbers, separate statements and separate reconciliation. The first
 * of them to gain an API will need its own adapter anyway, and splitting one
 * class in two at that point is a worse job than having two now.
 *
 * Note that both share a single §12 permission, `payments.confirm-mobile-money`:
 * the skill and the access needed to verify either is the same.
 */
final class AirtelMoneyAdapter extends OfflinePaymentAdapter
{
    public function code(): PaymentMethodCode
    {
        return PaymentMethodCode::AirtelMoney;
    }

    /**
     * @return list<string>
     */
    public function requiredAccountDetails(): array
    {
        return ['merchant_number'];
    }
}
