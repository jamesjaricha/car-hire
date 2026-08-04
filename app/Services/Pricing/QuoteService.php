<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PricingServiceContract;
use App\Contracts\QuoteServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\DateRange;
use App\DataTransferObjects\Quote;
use App\DataTransferObjects\QuoteOptions;
use App\Enums\SettingKey;
use App\Models\Vehicle;
use App\Support\Money;

/**
 * Builds the one price that is shown everywhere.
 *
 * Guideline §4:
 *
 *     hire_total       = daily_rate × days
 *     insurance_total  = per class, per day or flat
 *     extras_total     = sum of selected extras
 *     cross_border     = per destination country, if selected
 *     grand_total      = hire + insurance + extras + cross_border
 *
 *     displayed_price  = grand_total
 *     booking_deposit  = grand_total × 50%
 *     security_deposit = per class, shown separately
 */
final class QuoteService implements QuoteServiceContract
{
    public function __construct(
        private readonly PricingServiceContract $pricing,
        private readonly SettingsRepositoryContract $settings,
    ) {}

    public function quoteFor(
        Vehicle $vehicle,
        DateRange $range,
        ?QuoteOptions $options = null,
    ): Quote {
        $options ??= QuoteOptions::none();

        $hireTotal = $this->pricing->hireTotal($vehicle, $range);
        $insuranceTotal = $this->pricing->insuranceTotal($vehicle, $range);
        $extrasTotal = Money::of($options->extrasTotal);
        $crossBorderTotal = Money::of($options->crossBorderTotal);

        // The displayed price. Insurance is inside it, not added later.
        $grandTotal = Money::sum([
            $hireTotal,
            $insuranceTotal,
            $extrasTotal,
            $crossBorderTotal,
        ]);

        $depositPercentage = $this->depositPercentage();
        $bookingDeposit = Money::percentageOf($grandTotal, $depositPercentage);

        // Derived by subtraction rather than calculated as its own percentage.
        // Two independent roundings could each be individually correct and yet
        // fail to sum back to the total, leaving a booking permanently a ngwee
        // short of settled — and therefore permanently unable to be released.
        $balanceAfterDeposit = Money::subtract($grandTotal, $bookingDeposit);

        return new Quote(
            vehicleId: (int) $vehicle->getKey(),
            vehicleClassId: (int) $vehicle->vehicle_class_id,
            range: $range,
            chargeableDays: $range->chargeableDays(),

            dailyRate: $this->pricing->dailyRateFor($vehicle),
            hireTotal: $hireTotal,

            insuranceTotal: $insuranceTotal,
            insurancePriceMode: $this->pricing->insuranceModeFor($vehicle),
            insuranceExcessAmount: $this->pricing->insuranceExcessFor($vehicle),

            extrasTotal: $extrasTotal,
            crossBorderTotal: $crossBorderTotal,
            crossBorderCountry: $options->crossBorderCountry,

            grandTotal: $grandTotal,

            bookingDepositAmount: $bookingDeposit,
            balanceAfterDeposit: $balanceAfterDeposit,
            depositPercentage: $depositPercentage,

            // Quoted separately and never folded into the grand total: this is
            // refundable cash taken at the counter, not part of the hire price.
            securityDepositAmount: $this->pricing->securityDepositFor($vehicle),

            currency: (string) config('carhire.currency', 'ZMW'),
        );
    }

    /**
     * Clamped to something sane. A misconfigured 0% would secure bookings with
     * no money at all, and 100% would silently turn the deposit option into
     * paying in full — both would look like the platform working normally.
     */
    private function depositPercentage(): int
    {
        $configured = $this->settings->integer(SettingKey::BookingDepositPercentage, 50) ?? 50;

        return max(1, min(100, $configured));
    }
}
