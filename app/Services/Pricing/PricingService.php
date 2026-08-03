<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PricingServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\DateRange;
use App\Enums\InsurancePriceMode;
use App\Enums\SettingKey;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use RuntimeException;

/**
 * The single place that answers "what does this vehicle cost?".
 *
 * A vehicle's `daily_rate` and `security_deposit_amount` columns are nullable
 * overrides: null means inherit from the class. Reading those columns directly
 * anywhere else will silently price a hire at zero for every vehicle that has
 * not been given an override — which is most of them. Go through here.
 */
final class PricingService implements PricingServiceContract
{
    public function __construct(
        private readonly SettingsRepositoryContract $settings,
    ) {}

    public function dailyRateFor(Vehicle $vehicle): string
    {
        return $this->normalise(
            $vehicle->daily_rate ?? $this->classOf($vehicle)->daily_rate
        );
    }

    public function securityDepositFor(Vehicle $vehicle): string
    {
        return $this->normalise(
            $vehicle->security_deposit_amount ?? $this->classOf($vehicle)->security_deposit_amount
        );
    }

    public function insuranceExcessFor(Vehicle $vehicle): string
    {
        return $this->normalise($this->classOf($vehicle)->insurance_excess_amount);
    }

    public function turnaroundBufferMinutesFor(Vehicle $vehicle): int
    {
        $buffer = $this->classOf($vehicle)->turnaround_buffer_minutes;

        if ($buffer > 0) {
            return $buffer;
        }

        return $this->settings->integer(
            SettingKey::DefaultTurnaroundBufferMinutes,
            (int) config('carhire.fallback_turnaround_buffer_minutes', 120),
        ) ?? 120;
    }

    public function hireTotal(Vehicle $vehicle, DateRange $range): string
    {
        return $this->normalise(bcmul(
            $this->dailyRateFor($vehicle),
            (string) $range->chargeableDays(),
            $this->scale(),
        ));
    }

    public function insuranceTotal(Vehicle $vehicle, DateRange $range): string
    {
        $class = $this->classOf($vehicle);
        $price = $this->normalise($class->insurance_price);

        return match ($class->insurance_price_mode) {
            InsurancePriceMode::Flat => $price,
            InsurancePriceMode::PerDay => $this->normalise(
                bcmul($price, (string) $range->chargeableDays(), $this->scale())
            ),
        };
    }

    private function classOf(Vehicle $vehicle): VehicleClass
    {
        $class = $vehicle->relationLoaded('vehicleClass')
            ? $vehicle->vehicleClass
            : $vehicle->vehicleClass()->first();

        if (! $class instanceof VehicleClass) {
            throw new RuntimeException(
                "Vehicle [{$vehicle->registration}] has no vehicle class; it cannot be priced."
            );
        }

        return $class;
    }

    /**
     * Bring a value to the configured scale before it is used or compared.
     *
     * Values read back from SQL aggregates arrive unscaled ('300' not '300.00').
     * Skipping this step yields arithmetic that is numerically right but fails
     * exact string assertions, which is how it usually gets noticed — late.
     */
    private function normalise(string|int|float|null $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', $this->scale());
    }

    private function scale(): int
    {
        return (int) config('carhire.money_scale', 2);
    }
}
