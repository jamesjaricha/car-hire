<?php

declare(strict_types=1);

namespace App\Services\Payments\Adapters;

use App\Enums\PaymentMethodCode;

/**
 * Cash, handed over at the counter.
 *
 * The only method that needs no configuration at all: the customer walks in and
 * pays a person. That is also why it is the only one that survives the
 * short-notice rule of spec §8.2 — there is nothing to send and nothing to
 * verify against a statement afterwards.
 */
final class CashAdapter extends OfflinePaymentAdapter
{
    public function code(): PaymentMethodCode
    {
        return PaymentMethodCode::Cash;
    }
}
