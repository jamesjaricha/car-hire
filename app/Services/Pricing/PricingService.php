<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PricingServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\DateRange;
use App\Enums\InsurancePriceMode;
use App\Enums\SettingKey;
use App\Exceptions\VehicleClassNotPricedException;
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

    /**
     * A vehicle-level override wins, and a class figure is only consulted when
     * there is none — so a vehicle carrying its own deposit is sellable even
     * while its class is still undecided.
     */
    public function securityDepositFor(Vehicle $vehicle): string
    {
        if ($vehicle->security_deposit_amount !== null) {
            return $this->normalise($vehicle->security_deposit_amount);
        }

        return $this->normalise(
            $this->decided($this->classOf($vehicle), 'security_deposit_amount')
        );
    }

    public function insuranceExcessFor(Vehicle $vehicle): string
    {
        // No vehicle-level override exists for the excess: spec §10 prices the
        // waiver per class, so this is the class's decision or nothing.
        return $this->normalise(
            $this->decided($this->classOf($vehicle), 'insurance_excess_amount')
        );
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
        $price = $this->normalise($this->decided($class, 'insurance_price'));

        return match ($class->insurance_price_mode) {
            InsurancePriceMode::Flat => $price,
            InsurancePriceMode::PerDay => Money::multiply($price, $range->chargeableDays()),
        };
    }

    /**
     * The lowest daily rate any of these vehicles actually charges.
     *
     * Lives here rather than in the two controllers that need it, for the same
     * reason everything else in this class does: the class → vehicle override
     * chain is one rule, and two implementations of it agree exactly until one
     * of them is edited.
     *
     * The class is set onto each vehicle before pricing so `classOf()` finds it
     * already loaded — otherwise this issues a query per car, and a browse page
     * over a large class would fan out badly.
     *
     * @param  iterable<Vehicle>  $vehicles
     */
    public function lowestDailyRate(VehicleClass $class, iterable $vehicles): ?string
    {
        $lowest = null;

        foreach ($vehicles as $vehicle) {
            $vehicle->setRelation('vehicleClass', $class);

            $rate = $this->dailyRateFor($vehicle);

            if ($lowest === null || Money::compare($rate, $lowest) < 0) {
                $lowest = $rate;
            }
        }

        return $lowest;
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
     * A figure the business has actually decided, or a refusal.
     *
     * Null on any of the three §15 columns means undecided, not zero — see the
     * 2026-08-09 migration. Returning `Money::of(null)` here would give '0.00',
     * which is how an unpriced class came to advertise "no security deposit"
     * and "no excess" to customers in writing.
     *
     * `AvailabilityService` keeps such a class out of search results, so this
     * should not be reachable from the customer journey. It stays because the
     * booking engine has other entry points — a staff reassignment, a direct
     * quote — and the failure must be loud wherever it happens.
     */
    private function decided(VehicleClass $class, string $column): string
    {
        $value = $class->getAttribute($column);

        if ($value === null) {
            throw VehicleClassNotPricedException::missingDecisions($class);
        }

        return $value;
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
