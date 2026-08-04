<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\InsurancePriceMode;

/**
 * A complete price for one vehicle over one window.
 *
 * THE RULE THIS OBJECT EXISTS TO ENFORCE (spec §1.2)
 *
 * `grandTotal` is the number shown in search results, on the vehicle page, in
 * the basket, at checkout and on the confirmation. The same number, everywhere,
 * and it already includes the mandatory damage waiver. No mandatory charge may
 * appear later in the flow than the search result — charges may be itemised at
 * checkout, never introduced there.
 *
 * `securityDepositAmount` is the one thing quoted separately, and it must be
 * shown from search onward too. It is refundable cash taken at the counter
 * against damage, and it is NOT part of the grand total. It must never be
 * confused with `bookingDepositAmount`, which is a part-payment of the hire
 * itself — the specification calls that the single most likely misreading.
 */
final readonly class Quote
{
    public function __construct(
        public int $vehicleId,
        public int $vehicleClassId,
        public DateRange $range,
        public int $chargeableDays,

        public string $dailyRate,
        public string $hireTotal,

        public string $insuranceTotal,
        public InsurancePriceMode $insurancePriceMode,
        public string $insuranceExcessAmount,

        public string $extrasTotal,
        public string $crossBorderTotal,
        public ?string $crossBorderCountry,

        /** The displayed price. Everything mandatory is already in here. */
        public string $grandTotal,

        /** Part-payment of the hire that secures the booking. */
        public string $bookingDepositAmount,
        public string $balanceAfterDeposit,
        public int $depositPercentage,

        /** Refundable cash, at the counter. Additional to the grand total. */
        public string $securityDepositAmount,

        public string $currency,
    ) {}

    /**
     * What the customer pays now, given their choice at checkout.
     */
    public function amountDueNow(bool $payInFull): string
    {
        return $payInFull ? $this->grandTotal : $this->bookingDepositAmount;
    }

    /**
     * What remains owing before the vehicle can be released.
     */
    public function balanceDue(bool $payInFull): string
    {
        return $payInFull ? '0.00' : $this->balanceAfterDeposit;
    }

    /**
     * A stable representation of every priced element.
     *
     * Used to prove the price a customer saw in search is the price they are
     * charged at checkout — spec §14.4. Comparing signatures catches a drift
     * that comparing grand totals alone would miss, such as insurance moving
     * from the hire into extras while the total stays the same.
     */
    public function signature(): string
    {
        return implode('|', [
            $this->vehicleId,
            $this->range->start->toIso8601String(),
            $this->range->end->toIso8601String(),
            $this->hireTotal,
            $this->insuranceTotal,
            $this->extrasTotal,
            $this->crossBorderTotal,
            $this->grandTotal,
            $this->securityDepositAmount,
        ]);
    }

    public function matches(self $other): bool
    {
        return $this->signature() === $other->signature();
    }

    /**
     * Flattened to scalars for storage in the session.
     *
     * Serialising the object itself would work until the day this class gains
     * or loses a property, at which point every basket already in a live
     * session would fail to unserialise — for a customer, mid-checkout. Scalars
     * survive a deploy; PHP objects in a session do not.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'vehicle_class_id' => $this->vehicleClassId,
            'start_at' => $this->range->start->toIso8601String(),
            'end_at' => $this->range->end->toIso8601String(),
            'chargeable_days' => $this->chargeableDays,
            'daily_rate' => $this->dailyRate,
            'hire_total' => $this->hireTotal,
            'insurance_total' => $this->insuranceTotal,
            'insurance_price_mode' => $this->insurancePriceMode->value,
            'insurance_excess_amount' => $this->insuranceExcessAmount,
            'extras_total' => $this->extrasTotal,
            'cross_border_total' => $this->crossBorderTotal,
            'cross_border_country' => $this->crossBorderCountry,
            'grand_total' => $this->grandTotal,
            'booking_deposit_amount' => $this->bookingDepositAmount,
            'balance_after_deposit' => $this->balanceAfterDeposit,
            'deposit_percentage' => $this->depositPercentage,
            'security_deposit_amount' => $this->securityDepositAmount,
            'currency' => $this->currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            vehicleId: (int) $data['vehicle_id'],
            vehicleClassId: (int) $data['vehicle_class_id'],
            range: DateRange::of((string) $data['start_at'], (string) $data['end_at']),
            chargeableDays: (int) $data['chargeable_days'],
            dailyRate: (string) $data['daily_rate'],
            hireTotal: (string) $data['hire_total'],
            insuranceTotal: (string) $data['insurance_total'],
            insurancePriceMode: InsurancePriceMode::from((string) $data['insurance_price_mode']),
            insuranceExcessAmount: (string) $data['insurance_excess_amount'],
            extrasTotal: (string) $data['extras_total'],
            crossBorderTotal: (string) $data['cross_border_total'],
            crossBorderCountry: $data['cross_border_country'] === null
                ? null
                : (string) $data['cross_border_country'],
            grandTotal: (string) $data['grand_total'],
            bookingDepositAmount: (string) $data['booking_deposit_amount'],
            balanceAfterDeposit: (string) $data['balance_after_deposit'],
            depositPercentage: (int) $data['deposit_percentage'],
            securityDepositAmount: (string) $data['security_deposit_amount'],
            currency: (string) $data['currency'],
        );
    }
}
