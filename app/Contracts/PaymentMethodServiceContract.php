<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentMethodCode;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Decides which payment methods a customer may actually use.
 *
 * Three separate gates, all of which must pass: the operator's `enabled`
 * switch, the deployment's configuration flag, and the timing rules — the
 * four-hour short-notice cut-off and any per-method lead time.
 *
 * `assertSelectable()` is the one that matters for security. Spec §14.2
 * requires a disabled method submitted directly to the API to be refused, so
 * the check must happen where the value is used, not where the form is drawn.
 */
interface PaymentMethodServiceContract
{
    /**
     * Everything to render at checkout, including methods that are visible but
     * disabled so they can be shown greyed out as "Coming Soon".
     *
     * @return Collection<int, PaymentMethod>
     */
    public function displayable(): Collection;

    /**
     * Methods the customer may genuinely choose for this pickup time.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function selectableFor(CarbonImmutable $pickupAt, ?CarbonImmutable $now = null): Collection;

    /**
     * @throws PaymentMethodNotAvailableException when the code is unknown, the
     *                                            method is disabled, or the timing rules forbid it.
     */
    public function assertSelectable(
        PaymentMethodCode|string $code,
        CarbonImmutable $pickupAt,
        ?CarbonImmutable $now = null,
    ): PaymentMethod;
}
