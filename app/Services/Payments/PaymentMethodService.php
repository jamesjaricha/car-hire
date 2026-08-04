<?php

declare(strict_types=1);

namespace App\Services\Payments;

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
