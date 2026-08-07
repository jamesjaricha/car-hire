<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentAdapterContract;
use App\Contracts\PaymentAdapterResolverContract;
use App\Enums\PaymentMethodCode;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Services\Payments\Adapters\AirtelMoneyAdapter;
use App\Services\Payments\Adapters\BankTransferAdapter;
use App\Services\Payments\Adapters\CashAdapter;
use App\Services\Payments\Adapters\MtnMomoAdapter;

/**
 * Method code in, adapter out.
 *
 * The card methods have no adapter and asking for one is refused. That is the
 * correct answer rather than a gap: spec §3.2 has them visible and greyed out
 * as "Coming Soon", and the guideline says not to build stubs beyond the
 * interface. A stub would be a class that exists, resolves cleanly, and does
 * nothing — which is exactly how a card payment would one day appear to have
 * been taken.
 */
final class PaymentAdapterResolver implements PaymentAdapterResolverContract
{
    /** @var array<string, PaymentAdapterContract>|null */
    private ?array $adapters = null;

    public function for(PaymentMethodCode $code): PaymentAdapterContract
    {
        $adapter = $this->adapters()[$code->value] ?? null;

        if (! $adapter instanceof PaymentAdapterContract) {
            throw PaymentMethodNotAvailableException::noAdapter($code->value);
        }

        return $adapter;
    }

    public function has(PaymentMethodCode $code): bool
    {
        return isset($this->adapters()[$code->value]);
    }

    /**
     * @return array<string, PaymentAdapterContract>
     */
    private function adapters(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }

        $adapters = [];

        foreach ([new CashAdapter, new BankTransferAdapter, new MtnMomoAdapter, new AirtelMoneyAdapter] as $adapter) {
            $adapters[$adapter->code()->value] = $adapter;
        }

        return $this->adapters = $adapters;
    }
}
