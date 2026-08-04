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
use App\Support\Money;
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

    public function insuranceModeFor(Vehicle $vehicle): InsurancePriceMode
    {
        return $this->classOf($vehicle)->insurance_price_mode;
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
        return Money::multiply(
            $this->dailyRateFor($vehicle),
            $range->chargeableDays(),
        );
    }

    public function insuranceTotal(Vehicle $vehicle, DateRange $range): string
    {
        $class = $this->classOf($vehicle);
        $price = $this->normalise($class->insurance_price);

        return match ($class->insurance_price_mode) {
            InsurancePriceMode::Flat => $price,
            InsurancePriceMode::PerDay => Money::multiply($price, $range->chargeableDays()),
        };
    }

    /**
     * The vehicle's class, resolved once per model instance.
     *
     * Building a single quote asks for the rate, the deposit, the excess, the
     * insurance mode and the buffer — five calls. Without memoising, that is
     * five queries per vehicle, so a twenty-vehicle search page would issue a
     * hundred. The resolved class is cached onto the model as a normal Eloquent
     * relation, which also means an eager-loaded one is used as-is.
     */
    private function classOf(Vehicle $vehicle): VehicleClass
    {
        if (! $vehicle->relationLoaded('vehicleClass')) {
            $vehicle->setRelation('vehicleClass', $vehicle->vehicleClass()->first());
        }

        $class = $vehicle->vehicleClass;

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
     * Delegates to Money so there is exactly one implementation of this in the
     * codebase. Values read back from SQL arrive unscaled ('300' not '300.00'),
     * and two slightly different normalisers is how that starts producing
     * inconsistent answers.
     */
    private function normalise(string|int|null $value): string
    {
        return Money::of($value);
    }
}
