<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentMethodCode;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * How one provider behaves. Spec §4.
 *
 * "All payment providers are accessed through a common adapter interface so a
 * gateway can later be added without touching the checkout UI or booking
 * logic." That is the entire purpose of this interface, and it is why the
 * booking engine never asks "is this cash?" — it asks the adapter.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * There is no `charge()`, no `redirectUrl()`, no webhook handling. The
 * guideline is explicit that card gateways are out of scope and that no stubs
 * beyond the interface should be built. Every method below is one that the four
 * offline providers genuinely answer differently; inventing methods only a
 * gateway would implement would mean four classes of `throw new
 * NotImplementedException`, which is worse than no interface at all.
 *
 * When a gateway is added, it will need more than this. That is the moment to
 * widen the interface — with a real implementation in hand to check it against.
 */
interface PaymentAdapterContract
{
    public function code(): PaymentMethodCode;

    /**
     * Whether a person has to verify that the money arrived.
     *
     * True for all four offline methods. A gateway confirms its own payments,
     * and that difference is the reason this interface exists.
     */
    public function requiresManualConfirmation(): bool;

    /**
     * The `account_details` keys this provider needs before a customer can
     * actually be told where to send money.
     *
     * Cash needs none: the customer walks in. A bank transfer without an
     * account number is instructions to send money nowhere.
     *
     * @return list<string>
     */
    public function requiredAccountDetails(): array;

    /**
     * Which of those keys the operator has not supplied.
     *
     * @return list<string>
     */
    public function missingAccountDetails(PaymentMethod $method): array;

    public function isConfigured(PaymentMethod $method): bool;

    /**
     * What to tell the customer, with the merge fields of spec §4 filled in.
     */
    public function instructionsFor(
        Payment $payment,
        PaymentMethod $method,
        ?CarbonImmutable $deadlineAt = null,
    ): string;
}
