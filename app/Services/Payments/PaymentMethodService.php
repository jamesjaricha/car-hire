<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentAdapterResolverContract;
use App\Contracts\PaymentMethodServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Enums\PaymentMethodCode;
use App\Enums\SettingKey;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The gatekeeper for payment methods.
 *
 * The important method is assertSelectable(). Everything else here is for
 * drawing a checkout screen; that one is what stops a hand-built request from
 * booking a car on a payment method the business does not accept.
 *
 * It is written to fail closed at every step: an unrecognised code, a method
 * that is not in the database, a disabled method and a badly-timed method are
 * all refused with a distinct exception rather than falling through to a
 * default.
 */
final class PaymentMethodService implements PaymentMethodServiceContract
{
    public function __construct(
        private readonly SettingsRepositoryContract $settings,
        private readonly PaymentAdapterResolverContract $adapters,
    ) {}

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function displayable(): Collection
    {
        return PaymentMethod::query()->inDisplayOrder()->get();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function selectableFor(CarbonImmutable $pickupAt, ?CarbonImmutable $now = null): Collection
    {
        $hoursToPickup = $this->hoursToPickup($pickupAt, $now);
        $shortNotice = $hoursToPickup < $this->shortNoticeThresholdHours();

        return $this->displayable()
            ->filter(fn (PaymentMethod $method): bool => $method->isOfferable())
            // Switched on, but does the operator's configuration actually let a
            // customer pay by it? Instructions to transfer money to a blank
            // account number are instructions to send it nowhere.
            ->filter(fn (PaymentMethod $method): bool => $this->isConfigured($method))
            ->filter(function (PaymentMethod $method) use ($shortNotice, $hoursToPickup): bool {
                // Spec §8.2: with pickup imminent, nothing that has to be sent
                // and then verified is any use. Cash at the counter only.
                if ($shortNotice) {
                    return $method->isSettledAtBranch();
                }

                if ($method->min_lead_time_hours !== null
                    && $hoursToPickup < $method->min_lead_time_hours) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public function assertSelectable(
        PaymentMethodCode|string $code,
        CarbonImmutable $pickupAt,
        ?CarbonImmutable $now = null,
    ): PaymentMethod {
        $raw = $code instanceof PaymentMethodCode ? $code->value : $code;
        $resolved = PaymentMethodCode::tryFrom($raw);

        if (! $resolved instanceof PaymentMethodCode) {
            throw PaymentMethodNotAvailableException::unknown($raw);
        }

        $method = PaymentMethod::query()->where('code', $resolved->value)->first();

        if (! $method instanceof PaymentMethod) {
            throw PaymentMethodNotAvailableException::unknown($raw);
        }

        if (! $method->isOfferable()) {
            throw PaymentMethodNotAvailableException::notEnabled($raw);
        }

        if (! $this->isConfigured($method)) {
            throw PaymentMethodNotAvailableException::notConfigured(
                $raw,
                $this->adapters->for($method->code)->missingAccountDetails($method),
            );
        }

        $hoursToPickup = $this->hoursToPickup($pickupAt, $now);
        $threshold = $this->shortNoticeThresholdHours();

        if ($hoursToPickup < $threshold && ! $method->isSettledAtBranch()) {
            throw PaymentMethodNotAvailableException::tooCloseToPickup($raw, $threshold);
        }

        if ($method->min_lead_time_hours !== null
            && $hoursToPickup < $method->min_lead_time_hours) {
            throw PaymentMethodNotAvailableException::insufficientLeadTime(
                $raw,
                $method->min_lead_time_hours,
            );
        }

        return $method;
    }

    /**
     * Whether the operator has supplied what this method needs to be paid by.
     *
     * WHY THIS IS HERE AND NOT ON THE MODEL
     *
     * `PaymentMethod::isOfferable()` is asked by staff-facing code too —
     * `CounterPaymentService` and the panel's take-payment action both use it.
     * A bank transfer that has already landed must still be recordable at the
     * counter, because the money arrived whether or not anybody has typed an
     * account number into the admin panel. Putting the check on the model would
     * refuse to write down cash sitting on the desk.
     *
     * So it lives on the customer-facing gate only: `selectableFor()` draws the
     * checkout, `assertSelectable()` refuses a hand-built request, and neither
     * offers a method a customer could not actually pay by.
     *
     * A method with no adapter is treated as unconfigured rather than allowed
     * through. Today that means the card methods, which `isOfferable()` has
     * already excluded — but failing closed here means a future method that
     * arrives without an adapter is withheld rather than offered.
     */
    private function isConfigured(PaymentMethod $method): bool
    {
        if (! $this->adapters->has($method->code)) {
            return false;
        }

        return $this->adapters->for($method->code)->isConfigured($method);
    }

    /**
     * Compared as timestamps rather than through Carbon's diff helpers, whose
     * sign and return type have changed between major versions.
     */
    private function hoursToPickup(CarbonImmutable $pickupAt, ?CarbonImmutable $now): float
    {
        $now ??= CarbonImmutable::now();

        return ($pickupAt->getTimestamp() - $now->getTimestamp()) / 3600;
    }

    private function shortNoticeThresholdHours(): int
    {
        return $this->settings->integer(SettingKey::ShortNoticeThresholdHours, 4) ?? 4;
    }
}
