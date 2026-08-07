<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentMethodCode;
use App\Exceptions\PaymentMethodNotAvailableException;

/**
 * Finds the adapter for a payment method.
 *
 * The one place in the codebase that maps a method code to behaviour. Anywhere
 * else doing that with a match statement is a second map to keep in step.
 */
interface PaymentAdapterResolverContract
{
    /**
     * @throws PaymentMethodNotAvailableException when the method
     *                                            has no adapter — which today means one of the card methods, and
     *                                            is a refusal rather than an oversight.
     */
    public function for(PaymentMethodCode $code): PaymentAdapterContract;

    public function has(PaymentMethodCode $code): bool;
}
