<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\QuoteServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\DateRange;
use App\DataTransferObjects\QuoteOptions;
use App\Enums\InsurancePriceMode;
use App\Enums\SettingKey;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuoteServiceContract $quotes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes = app(QuoteServiceContract::class);
    }

    public function test_the_grand_total_is_the_sum_of_every_line(): void
    {
        $vehicle = $this->vehicleWith();

        $quote = $this->quotes->quoteFor($vehicle, $this->threeDayHire());

        $this->assertSame('1950.00', $quote->hireTotal);      // 650 × 3
        $this->assertSame('360.00', $quote->insuranceTotal);  // 120 × 3
        $this->assertSame('0.00', $quote->extrasTotal);
        $this->assertSame('0.00', $quote->crossBorderTotal);
        $this->assertSame('2310.00', $quote->grandTotal);
    }

    /**
     * Spec §1.2: the displayed price must already include the mandatory damage
     * waiver. No mandatory charge may be introduced after the search result.
     */
    public function test_insurance_is_inside_the_displayed_price_not_added_later(): void
    {
        $vehicle = $this->vehicleWith();

        $quote = $this->quotes->quoteFor($vehicle, $this->threeDayHire());

        $this->assertSame(
            Money::add($quote->hireTotal, $quote->insuranceTotal),
            $quote->grandTotal,
        );
        $this->assertSame(1, Money::compare($quote->grandTotal, $quote->hireTotal));
    }

    /**
     * Spec §6: the security deposit is refundable cash taken at the counter.
     * It is quoted separately and must never be folded into the hire price.
     */
    public function test_the_security_deposit_sits_outside_the_grand_total(): void
    {
        $vehicle = $this->vehicleWith(['security_deposit_amount' => '1500.00']);

        $quote = $this->quotes->quoteFor($vehicle, $this->threeDayHire());

        $this->assertSame('1500.00', $quote->securityDepositAmount);
        $this->assertSame('2310.00', $quote->grandTotal);

        // The two deposits are different things and must not be equal by
        // accident either.
        $this->assertNotSame($quote->securityDepositAmount, $quote->bookingDepositAmount);
    }

    public function test_the_booking_deposit_is_half_the_grand_total(): void
    {
        $vehicle = $this->vehicleWith();

        $quote = $this->quotes->quoteFor($vehicle, $this->threeDayHire());

        $this->assertSame(50, $quote->depositPercentage);
        $this->assertSame('1155.00', $quote->bookingDepositAmount);
        $this->assertSame('1155.00', $quote->balanceAfterDeposit);
    }

    public function test_an_awkward_total_still_splits_without_losing_a_ngwee(): void
    {
        // 651.85 × 3 = 1955.55, whose half is 977.775. Truncation would give
        // 977.77 twice and lose a ngwee, leaving the booking permanently
        // unsettleable and therefore never releasable.
        $vehicle = $this->vehicleWith([
            'daily_rate' => '651.85',
            'insurance_price' => '0.00',
        ]);

        $quote = $this->quotes->quoteFor($vehicle, $this->threeDayHire());

        $this->assertSame('1955.55', $quote->grandTotal);
        $this->assertSame('977.78', $quote->bookingDepositAmount);
        $this->assertSame('977.77', $quote->balanceAfterDeposit);

        $this->assertSame(
            $quote->grandTotal,
            Money::add($quote->bookingDepositAmount, $quote->balanceAfterDeposit),
        );
    }

    public function test_flat_insurance_is_charged_once_however_long_the_hire(): void
    {
        $vehicle = $this->vehicleWith([
            'insurance_price' => '400.00',
            'insurance_price_mode' => InsurancePriceMode::Flat,
        ]);

        $short = $this->quotes->quoteFor($vehicle, $this->threeDayHire());
        $long = $this->quotes->quoteFor(
            $vehicle,
            DateRange::of('2026-09-01T09:00:00Z', '2026-09-15T09:00:00Z'),
        );

        $this->assertSame('400.00', $short->insuranceTotal);
        $this->assertSame('400.00', $long->insuranceTotal);
        $this->assertSame(InsurancePriceMode::Flat, $short->insurancePriceMode);
    }

    public function test_extras_and_cross_border_join_the_grand_total(): void
    {
        $vehicle = $this->vehicleWith();

        $quote = $this->quotes->quoteFor(
            $vehicle,
            $this->threeDayHire(),
            new QuoteOptions(
                extrasTotal: '250.00',
                crossBorderTotal: '1800.00',
                crossBorderCountry: 'ZW',
            ),
        );

        $this->assertSame('250.00', $quote->extrasTotal);
        $this->assertSame('1800.00', $quote->crossBorderTotal);
        $this->assertSame('ZW', $quote->crossBorderCountry);
        $this->assertSame('4360.00', $quote->grandTotal); // 1950 + 360 + 250 + 1800

        // And the deposit follows the larger total, not the hire alone.
        $this->assertSame('2180.00', $quote->bookingDepositAmount);
    }

    public function test_paying_in_full_leaves_nothing_owing(): void
    {
        $quote = $this->quotes->quoteFor($this->vehicleWith(), $this->threeDayHire());

        $this->assertSame('2310.00', $quote->amountDueNow(payInFull: true));
        $this->assertSame('0.00', $quote->balanceDue(payInFull: true));

        $this->assertSame('1155.00', $quote->amountDueNow(payInFull: false));
        $this->assertSame('1155.00', $quote->balanceDue(payInFull: false));
    }

    /**
     * Spec §14.4: the price in search results equals the price at checkout.
     */
    public function test_quoting_the_same_basket_twice_gives_an_identical_price(): void
    {
        $vehicle = $this->vehicleWith();
        $range = $this->threeDayHire();

        $atSearch = $this->quotes->quoteFor($vehicle, $range);
        $atCheckout = $this->quotes->quoteFor($vehicle->fresh(), $range);

        $this->assertTrue($atSearch->matches($atCheckout));
        $this->assertSame($atSearch->grandTotal, $atCheckout->grandTotal);
    }

    public function test_the_signature_notices_a_price_change(): void
    {
        $vehicle = $this->vehicleWith();
        $range = $this->threeDayHire();

        $before = $this->quotes->quoteFor($vehicle, $range);

        VehicleClass::query()
            ->whereKey($vehicle->vehicle_class_id)
            ->update(['daily_rate' => '900.00']);

        // A fresh instance, because a model caches its loaded relations — the
        // original object legitimately still holds the class it was quoted with.
        $after = $this->quotes->quoteFor($vehicle->fresh(), $range);

        $this->assertFalse($before->matches($after));
        $this->assertSame('2700.00', $after->hireTotal);
    }

    public function test_the_deposit_percentage_comes_from_settings(): void
    {
        app(SettingsRepositoryContract::class)
            ->set(SettingKey::BookingDepositPercentage, 30);

        $quote = $this->quotes->quoteFor($this->vehicleWith(), $this->threeDayHire());

        $this->assertSame(30, $quote->depositPercentage);
        $this->assertSame('693.00', $quote->bookingDepositAmount); // 30% of 2310
        $this->assertSame('1617.00', $quote->balanceAfterDeposit);
    }

    public function test_a_nonsensical_deposit_percentage_is_clamped(): void
    {
        // Zero would secure bookings with no money at all, and it would look
        // exactly like the platform working normally.
        app(SettingsRepositoryContract::class)
            ->set(SettingKey::BookingDepositPercentage, 0);

        $quote = $this->quotes->quoteFor($this->vehicleWith(), $this->threeDayHire());

        $this->assertSame(1, $quote->depositPercentage);
        $this->assertTrue(Money::isPositive($quote->bookingDepositAmount));
    }

    public function test_a_vehicle_rate_override_flows_through_to_the_quote(): void
    {
        $class = VehicleClass::factory()->create([
            'daily_rate' => '650.00',
            'insurance_price' => '0.00',
        ]);

        $vehicle = Vehicle::factory()->withRateOverride('1000.00')->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
        ]);

        $quote = $this->quotes->quoteFor($vehicle, $this->threeDayHire());

        $this->assertSame('1000.00', $quote->dailyRate);
        $this->assertSame('3000.00', $quote->hireTotal);
    }

    private function threeDayHire(): DateRange
    {
        return DateRange::of('2026-09-01T09:00:00Z', '2026-09-04T09:00:00Z');
    }

    /**
     * @param  array<string, mixed>  $classAttributes
     */
    private function vehicleWith(array $classAttributes = []): Vehicle
    {
        $class = VehicleClass::factory()->create([
            'daily_rate' => '650.00',
            'insurance_price' => '120.00',
            'insurance_price_mode' => InsurancePriceMode::PerDay,
            'insurance_excess_amount' => '4000.00',
            'security_deposit_amount' => '1500.00',
            ...$classAttributes,
        ]);

        return Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'daily_rate' => null,
            'security_deposit_amount' => null,
        ]);
    }
}
