<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentDeadlineCalculatorContract;
use App\Enums\PaymentMethodCode;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentDeadlineCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PaymentDeadlineCalculatorContract $calculator;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // The guideline's requirement is that this works unattended at 21:00 on
        // a Sunday, so the clock is frozen rather than left to chance.
        $this->now = CarbonImmutable::parse('2026-09-06T21:00:00Z');
        $this->travelTo($this->now);

        $this->calculator = app(PaymentDeadlineCalculatorContract::class);
    }

    /**
     * Guideline §6: "Pickup in 12 hours, bank transfer → deadline is pickup − 2h".
     */
    public function test_a_near_pickup_beats_the_methods_own_hold_duration(): void
    {
        $pickupAt = $this->now->addHours(12);

        $window = $this->calculator->calculate($this->bankTransfer(), $pickupAt);

        // Not 48 hours. The transfer window is irrelevant when the customer
        // collects the car in twelve hours.
        $this->assertTrue($window->deadlineAt->equalTo($pickupAt->subHours(2)));
        $this->assertTrue($window->placesHold);
        $this->assertFalse($window->isShortNotice);
    }

    public function test_a_distant_pickup_lets_the_method_duration_apply(): void
    {
        $pickupAt = $this->now->addDays(5);

        $window = $this->calculator->calculate($this->bankTransfer(), $pickupAt);

        $this->assertTrue($window->deadlineAt->equalTo($this->now->addHours(48)));
    }

    public function test_mobile_moneys_short_window_applies_before_the_pickup_margin(): void
    {
        // Six hours is less than the ten available before pickup minus two.
        $pickupAt = $this->now->addHours(12);

        $window = $this->calculator->calculate($this->method(PaymentMethodCode::MtnMomo), $pickupAt);

        $this->assertTrue($window->deadlineAt->equalTo($this->now->addHours(6)));
    }

    /**
     * Guideline §6: "Pickup in 3 hours → no online methods offered".
     */
    public function test_an_imminent_pickup_produces_no_deadline_and_no_hold(): void
    {
        $window = $this->calculator->calculate($this->bankTransfer(), $this->now->addHours(3));

        $this->assertTrue($window->isShortNotice);
        $this->assertNull($window->deadlineAt);
        $this->assertNull($window->reminderAt);

        // The important one: nothing is claimed. Spec §8.2 says availability is
        // first-come at the counter for these bookings, so holding a vehicle
        // would take it off sale against the specification.
        $this->assertFalse($window->placesHold);
    }

    public function test_the_short_notice_boundary_is_exclusive(): void
    {
        // "Less than 4 hours away" — exactly four hours is still a normal booking.
        $window = $this->calculator->calculate($this->bankTransfer(), $this->now->addHours(4));

        $this->assertFalse($window->isShortNotice);
        $this->assertTrue($window->placesHold);
        $this->assertTrue($window->deadlineAt->equalTo($this->now->addHours(2)));
    }

    public function test_a_pickup_in_the_past_never_produces_a_deadline(): void
    {
        // Rejecting this booking is the booking service's job. What matters
        // here is that no deadline is invented for it.
        $window = $this->calculator->calculate($this->bankTransfer(), $this->now->subHour());

        $this->assertTrue($window->isShortNotice);
        $this->assertNull($window->deadlineAt);
        $this->assertFalse($window->placesHold);
    }

    public function test_the_reminder_fires_once_a_quarter_of_the_window_remains(): void
    {
        $this->seedSettings();

        // 48-hour window: reminder after 36 hours, leaving 12.
        $window = $this->calculator->calculate($this->bankTransfer(), $this->now->addDays(5));

        $this->assertTrue($window->reminderAt->equalTo($this->now->addHours(36)));
        $this->assertTrue($window->reminderAt->lessThan($window->deadlineAt));
    }

    public function test_hold_durations_come_from_the_row_not_from_a_constant(): void
    {
        // The operator can change how long a method holds a vehicle without a
        // deploy, so the calculator must read the stored value.
        $method = $this->bankTransfer();
        $method->update(['hold_duration_hours' => 3]);

        $window = $this->calculator->calculate($method->fresh(), $this->now->addDays(5));

        $this->assertTrue($window->deadlineAt->equalTo($this->now->addHours(3)));
    }

    private function bankTransfer(): PaymentMethod
    {
        return $this->method(PaymentMethodCode::BankTransfer);
    }

    private function method(PaymentMethodCode $code): PaymentMethod
    {
        return PaymentMethod::factory()->code($code)->create();
    }

    private function seedSettings(): void
    {
        $this->seed(SettingsSeeder::class);
    }
}
