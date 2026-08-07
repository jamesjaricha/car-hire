<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\CounterPaymentServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\SettingKey;
use App\Enums\StaffRole;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\PaymentNotConfirmableException;
use App\Exceptions\PaymentNotRecordableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money handed over in person: recorded and confirmed in one step.
 *
 * The step is removed from the person at the desk, not from the system. Every
 * test here that passes should also demonstrate that a guard beneath is still
 * doing its job.
 */
final class CounterPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CounterPaymentServiceContract $counter;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->counter = app(CounterPaymentServiceContract::class);
    }

    public function test_taking_the_balance_settles_the_booking(): void
    {
        $booking = $this->partPaidBooking();

        $result = $this->counter->take($this->manager(), $booking, PaymentMethodCode::Cash, '1155.00');

        $this->assertSame('2310.00', $result->amountPaid);
        $this->assertSame('0.00', $result->balanceDue);
        $this->assertSame(BookingPaymentStatus::PaidInFull, $result->paymentStatus);
        $this->assertFalse($result->hasOutstandingBalance());
    }

    public function test_the_receipt_records_the_staff_member_who_took_it(): void
    {
        // Not the customer's checkout. Claiming otherwise would put a payment
        // in the trail with nobody accountable for it.
        $booking = $this->partPaidBooking();
        $manager = $this->manager();

        $result = $this->counter->take($manager, $booking, PaymentMethodCode::Cash, '1155.00');

        $this->assertSame($manager->getKey(), $result->payment->recorded_by_user_id);
        $this->assertSame(PaymentStatus::Confirmed, $result->payment->status);
    }

    /**
     * The receipt is raised for what is outstanding, not for the deposit or the
     * full total. A balance is neither.
     */
    public function test_the_receipt_expects_the_outstanding_balance(): void
    {
        $booking = $this->partPaidBooking();

        $result = $this->counter->take($this->manager(), $booking, PaymentMethodCode::Cash, '1155.00');

        $this->assertSame('1155.00', $result->payment->expected_amount);
        $this->assertFalse($result->payment->is_deposit);
    }

    public function test_paying_less_than_the_balance_is_a_shortfall(): void
    {
        $booking = $this->partPaidBooking();

        $result = $this->counter->take($this->manager(), $booking, PaymentMethodCode::Cash, '900.00');

        $this->assertTrue($result->hasShortfall);
        $this->assertSame('255.00', $result->shortfallAmount);
        $this->assertSame('255.00', $result->balanceDue);
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $result->paymentStatus);
    }

    public function test_it_leaves_exactly_one_receipt_and_one_confirmation(): void
    {
        $booking = $this->partPaidBooking();

        $this->counter->take($this->manager(), $booking, PaymentMethodCode::Cash, '1155.00');

        // The deposit receipt plus the counter one.
        $this->assertSame(2, Payment::query()->where('booking_id', $booking->getKey())->count());
        $this->assertDatabaseCount('payment_confirmations', 2);
    }

    // --- The guards beneath are still guarding -----------------------------

    /**
     * §12: a counter clerk may take cash, subject to §15.12. They may not sign
     * off a bank transfer, and going through the counter service must not be a
     * way around that.
     */
    public function test_a_counter_clerk_cannot_take_a_bank_transfer(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, true);

        $booking = $this->partPaidBooking();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->counter->take($this->clerk(), $booking, PaymentMethodCode::BankTransfer, '1155.00');
    }

    public function test_a_counter_clerk_cannot_take_cash_while_the_setting_is_off(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, false);

        $booking = $this->partPaidBooking();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->counter->take($this->clerk(), $booking, PaymentMethodCode::Cash, '1155.00');
    }

    public function test_a_counter_clerk_may_take_cash_once_the_setting_is_on(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, true);

        $booking = $this->partPaidBooking();

        $result = $this->counter->take($this->clerk(), $booking, PaymentMethodCode::Cash, '1155.00');

        $this->assertSame('0.00', $result->balanceDue);
    }

    /**
     * The whole point of one transaction. A refused confirmation must not leave
     * a receipt behind — money that looks unpaid and cannot be confirmed later,
     * because its reference is already spent.
     */
    public function test_a_refusal_leaves_no_orphaned_receipt(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, false);

        $booking = $this->partPaidBooking();
        $before = Payment::query()->where('booking_id', $booking->getKey())->count();

        try {
            $this->counter->take($this->clerk(), $booking, PaymentMethodCode::Cash, '1155.00');
            $this->fail('The counter payment should have been refused.');
        } catch (StaffPermissionDeniedException) {
            // Expected.
        }

        $this->assertSame($before, Payment::query()->where('booking_id', $booking->getKey())->count());
        $this->assertSame('1155.00', $booking->refresh()->balance_due);
    }

    public function test_money_cannot_be_taken_against_a_cancelled_booking(): void
    {
        $booking = $this->partPaidBooking(['status' => BookingStatus::CancelledNonPayment]);

        $this->expectException(PaymentNotConfirmableException::class);

        $this->counter->take($this->manager(), $booking, PaymentMethodCode::Cash, '1155.00');
    }

    public function test_a_zero_amount_is_refused(): void
    {
        $booking = $this->partPaidBooking();

        $this->expectException(PaymentNotRecordableException::class);

        $this->counter->take($this->manager(), $booking, PaymentMethodCode::Cash, '0.00');
    }

    public function test_a_disabled_method_is_refused_even_for_staff(): void
    {
        // A method the operator switched off is off for everyone. Cards are
        // disabled at MVP and have no adapter either.
        $booking = $this->partPaidBooking();

        $this->expectException(PaymentMethodNotAvailableException::class);

        $this->counter->take($this->manager(), $booking, PaymentMethodCode::CreditCard, '1155.00');
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * A booking whose deposit is confirmed and whose balance is outstanding.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function partPaidBooking(array $attributes = []): Booking
    {
        $booking = Booking::factory()->create(array_merge([
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::PartiallyPaid,
            'pay_in_full' => false,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '1155.00',
            'balance_due' => '1155.00',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
        ], $attributes));

        // The deposit that was already taken, so the recomputed total has
        // something real to add to.
        $deposit = Payment::factory()->forBooking($booking)->deposit()->create([
            'payment_reference' => $booking->reference.'-1',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'status' => PaymentStatus::Confirmed,
            'amount' => '1155.00',
        ]);

        $deposit->confirmation()->create([
            'confirmed_by_user_id' => User::factory()->create()->getKey(),
            'amount_confirmed' => '1155.00',
            'confirmed_at' => $this->now->subDay(),
        ]);

        return $booking;
    }

    private function method(PaymentMethodCode $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code->value)->sole();
    }

    private function manager(): User
    {
        return User::factory()->withRole(StaffRole::BranchManager)->create();
    }

    private function clerk(): User
    {
        return User::factory()->withRole(StaffRole::CounterClerk)->create();
    }
}
