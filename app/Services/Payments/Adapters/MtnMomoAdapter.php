<?php

declare(strict_types=1);

namespace App\Services\Payments\Adapters;

use App\Enums\PaymentMethodCode;

/**
 * MTN Mobile Money, paid to a merchant number.
 *
 * NOT a gateway integration. Spec §3.1 is explicit: at MVP this is manual
 * verification against a merchant or till number, exactly like a bank transfer.
 * No API, no PCI scope, no gateway credentials.
 *
 * The guideline warns that these statements lag and that till payments often do
 * not carry the reference the customer was given, which is why the platform has
 * an unmatched payments queue from day one rather than as a retrofit.
 */
final class MtnMomoAdapter extends OfflinePaymentAdapter
{
    public function code(): PaymentMethodCode
    {
        return PaymentMethodCode::MtnMomo;
    }

    /**
     * @return list<string>
     */
    public function requiredAccountDetails(): array
    {
        return ['merchant_number'];
    }
}
