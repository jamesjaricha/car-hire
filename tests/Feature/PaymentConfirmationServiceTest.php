<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentConfirmationServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
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
use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentConfirmationServiceContract $confirmations;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->confirmations = app(PaymentConfirmationServiceContract::class);
    }

    // --- The ordinary path -----------------------------------------------

    public function test_confirming_the_chosen_deposit_confirms_the_booking(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $result = $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $this->assertSame(BookingStatus::PendingPayment, $result->bookingStatusBefore);
        $this->assertSame(BookingStatus::Confirmed, $result->bookingStatusAfter);
        $this->assertTrue($result->bookingStatusChanged());

        $booking->refresh();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNotNull($booking->confirmed_at);
        $this->assertSame('1155.00', $booking->amount_paid);
        $this->assertSame('1155.00', $booking->balance_due);
    }

    /**
     * Spec §5 and §14.3. The deposit confirms the booking but does not settle
     * the hire — the two are easy to conflate, and a vehicle released on the
     * strength of the first is a car given away half paid for.
     */
    public function test_a_confirmed_deposit_still_leaves_the_hire_partly_paid(): void
    {
        [, $payment] = $this->bookingAwaitingDeposit();

        $result = $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $result->paymentStatus);
        $this->assertTrue($result->isConfirmedButUnsettled());
        $this->assertTrue($result->hasOutstandingBalance());
    }

    public function test_paying_in_full_settles_the_hire(): void
    {
        [$booking, $payment] = $this->bookingAwaitingFullPayment();

        $result = $this->confirmations->confirm($this->manager(), $payment, '2310.00');

        $this->assertSame(BookingPaymentStatus::PaidInFull, $result->paymentStatus);
        $this->assertSame('0.00', $result->balanceDue);
        $this->assertFalse($result->hasOutstandingBalance());
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_the_payment_records_what_actually_arrived(): void
    {
        [, $payment] = $this->bookingAwaitingDeposit();

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('1155.00', $payment->amount);
        $this->assertSame(1, $payment->confirmation()->count());
    }

    // --- Underpayment ------------------------------------------------------

    /**
     * The locked decision: a booking confirms only when the amount the customer
     * chose to pay is met. Someone who sent less has not done what was asked,
     * but they still have until the deadline to send the rest — and cancelling
     * them while their money sits in the operator's account would be
     * indefensible.
     */
    public function test_a_short_deposit_leaves_the_booking_pending_with_its_deadline_intact(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();
        $deadline = $booking->payment_deadline_at;

        $result = $this->confirmations->confirm($this->manager(), $payment, '500.00');

        $this->assertFalse($result->bookingStatusChanged());
        $this->assertSame(BookingStatus::PendingPayment, $result->bookingStatusAfter);

        $booking->refresh();

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertNull($booking->confirmed_at);
        $this->assertTrue($booking->payment_deadline_at->equalTo($deadline));

        // The money is still theirs and still counted.
        $this->assertSame('500.00', $booking->amount_paid);
        $this->assertSame('1810.00', $booking->balance_due);
        $this->assertSame(BookingPaymentStatus::PartiallyPaid, $booking->payment_status);
    }

    public function test_a_short_payment_is_reported_as_a_shortfall(): void
    {
        [, $payment] = $this->bookingAwaitingDeposit();

        $result = $this->confirmations->confirm($this->manager(), $payment, '900.00');

        $this->assertTrue($result->hasShortfall);
        $this->assertSame('255.00', $result->shortfallAmount);
    }

    public function test_a_second_payment_can_settle_a_short_first_one(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $this->confirmations->confirm($this->manager(), $payment, '500.00');

        $second = Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-2',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'expected_amount' => '655.00',
            'amount' => '0.00',
        ]);

        $result = $this->confirmations->confirm($this->manager(), $second, '655.00');

        // Summed from scratch, never incremented.
        $this->assertSame('1155.00', $result->amountPaid);
        $this->assertSame(BookingStatus::Confirmed, $result->bookingStatusAfter);
    }

    // --- Overpayment -------------------------------------------------------

    public function test_an_overpayment_never_produces_a_negative_balance(): void
    {
        // A negative balance is not a debt owed the other way. Showing one
        // invites somebody to treat it as a credit against a future hire.
        [, $payment] = $this->bookingAwaitingFullPayment();

        $result = $this->confirmations->confirm($this->manager(), $payment, '2500.00');

        $this->assertSame('0.00', $result->balanceDue);
        $this->assertTrue($result->isOverpaid);
        $this->assertSame('190.00', $result->overpaidAmount);
        $this->assertSame(BookingPaymentStatus::PaidInFull, $result->paymentStatus);
    }

    // --- Cross-border ------------------------------------------------------

    /**
     * Spec §7.3 and §11: payment confirmation does not confirm a cross-border
     * booking. It waits for the authorisation letter, the TIP paperwork and the
     * insurance extension.
     */
    public function test_a_cross_border_booking_awaits_paperwork_rather_than_confirming(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit(['cross_border_country' => 'ZW']);

        $result = $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $this->assertSame(BookingStatus::AwaitingCrossBorder, $result->bookingStatusAfter);
        $this->assertSame(BookingStatus::AwaitingCrossBorder, $booking->refresh()->status);

        // Not confirmed, so not timestamped as such.
        $this->assertNull($booking->confirmed_at);
    }

    // --- The hold ----------------------------------------------------------

    /**
     * The bug this chunk found.
     *
     * A hold is created with expires_at set to the PAYMENT DEADLINE. Nothing
     * moved it, so once the deadline passed the hold stopped claiming, the
     * availability query stopped seeing it, and the vehicle went back on sale
     * in the middle of a hire that had been paid for. Both AvailabilityService
     * and VehicleHoldService::place() decide from holds alone.
     */
    public function test_confirming_extends_the_hold_to_the_end_of_the_hire(): void
    {
        [$booking, $payment, $hold] = $this->bookingWithHold();

        $this->assertTrue($hold->expires_at->equalTo($booking->payment_deadline_at));

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $hold->refresh();

        $this->assertTrue(
            $hold->expires_at->equalTo($hold->end_at),
            'A confirmed booking must hold its vehicle until the hire ends.',
        );
    }

    public function test_a_confirmed_bookings_vehicle_stays_claimed_past_the_payment_deadline(): void
    {
        [$booking, $payment, $hold] = $this->bookingWithHold();

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        // Well past the old deadline, comfortably inside the hire.
        $this->travelTo($booking->payment_deadline_at->addHours(1));

        $this->assertSame(
            1,
            VehicleHold::query()->whereKey($hold->getKey())->stillClaiming()->count(),
            'The vehicle has returned to sale during a hire that has been paid for.',
        );
    }

    public function test_a_short_notice_booking_has_no_hold_to_extend(): void
    {
        // Spec §8.2 places no hold at all. Confirming must not fall over.
        [$booking, $payment] = $this->bookingAwaitingDeposit([
            'is_short_notice' => true,
            'payment_deadline_at' => null,
        ]);

        $result = $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $this->assertSame(BookingStatus::Confirmed, $result->bookingStatusAfter);
        $this->assertSame(0, VehicleHold::query()->where('booking_id', $booking->getKey())->count());
    }

    // --- Refusals ----------------------------------------------------------

    public function test_the_same_payment_cannot_be_confirmed_twice(): void
    {
        [, $payment] = $this->bookingAwaitingDeposit();

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $this->expectException(PaymentNotConfirmableException::class);

        $this->confirmations->confirm($this->manager(), $payment->refresh(), '1155.00');
    }

    public function test_a_second_confirmation_leaves_the_balance_untouched(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        try {
            $this->confirmations->confirm($this->manager(), $payment->refresh(), '1155.00');
            $this->fail('The second confirmation should have been refused.');
        } catch (PaymentNotConfirmableException) {
            // Expected.
        }

        $this->assertSame('1155.00', $booking->refresh()->amount_paid);
        $this->assertSame(1, $booking->payments()->count());
    }

    public function test_money_with_no_booking_cannot_be_confirmed(): void
    {
        // Attribution and verification are different judgements. Confirming
        // money against no booking would settle nothing.
        $payment = Payment::factory()->unmatched('1155.00')->create();

        $this->expectException(PaymentNotConfirmableException::class);

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');
    }

    /**
     * Found by the pre-merge audit.
     *
     * An unmatched receipt can be attributed to a booking, and the sweep can
     * have cancelled that booking overnight. Without this guard the receipt was
     * confirmable: the balance would be recomputed, the booking would stay
     * cancelled, and nothing anywhere would say the customer was owed their
     * money back. It would exist only as a row in `payments`.
     */
    public function test_money_cannot_be_confirmed_against_a_cancelled_booking(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $booking->forceFill([
            'status' => BookingStatus::CancelledNonPayment,
            'cancelled_at' => $this->now,
        ])->save();

        $this->expectException(PaymentNotConfirmableException::class);

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');
    }

    public function test_a_refusal_on_a_cancelled_booking_takes_no_money(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $booking->forceFill(['status' => BookingStatus::CancelledByCustomer])->save();

        try {
            $this->confirmations->confirm($this->manager(), $payment, '1155.00');
            $this->fail('The confirmation should have been refused.');
        } catch (PaymentNotConfirmableException) {
            // Expected.
        }

        $this->assertDatabaseCount('payment_confirmations', 0);
        $this->assertSame('0.00', $booking->refresh()->amount_paid);
        $this->assertSame(PaymentStatus::AwaitingPayment, $payment->refresh()->status);
    }

    /**
     * The balance at the counter, on a booking already confirmed by its
     * deposit. This is the case the guard must NOT block.
     */
    public function test_the_balance_can_still_be_paid_on_a_confirmed_booking(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $balance = Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-2',
            'payment_method_code' => PaymentMethodCode::Cash,
            'expected_amount' => '1155.00',
            'amount' => '0.00',
        ]);

        $result = $this->confirmations->confirm($this->manager(), $balance, '1155.00');

        $this->assertSame('2310.00', $result->amountPaid);
        $this->assertSame('0.00', $result->balanceDue);
        $this->assertSame(BookingPaymentStatus::PaidInFull, $result->paymentStatus);
        $this->assertSame(BookingStatus::Confirmed, $result->bookingStatusAfter);
    }

    public function test_an_expired_payment_cannot_be_confirmed(): void
    {
        [, $payment] = $this->bookingAwaitingDeposit();
        $payment->forceFill(['status' => PaymentStatus::PaymentExpired])->save();

        $this->expectException(PaymentNotConfirmableException::class);

        $this->confirmations->confirm($this->manager(), $payment, '1155.00');
    }

    public function test_a_zero_amount_cannot_be_confirmed(): void
    {
        [, $payment] = $this->bookingAwaitingDeposit();

        $this->expectException(PaymentNotRecordableException::class);

        $this->confirmations->confirm($this->manager(), $payment, '0.00');
    }

    // --- Permissions (spec §12) -------------------------------------------

    public function test_a_counter_clerk_cannot_confirm_a_bank_transfer(): void
    {
        // Verifying a transfer means reading a statement they do not have.
        [, $payment] = $this->bookingAwaitingDeposit();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->confirmations->confirm($this->clerk(), $payment, '1155.00');
    }

    public function test_a_refused_confirmation_writes_nothing(): void
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        try {
            $this->confirmations->confirm($this->clerk(), $payment, '1155.00');
            $this->fail('The confirmation should have been refused.');
        } catch (StaffPermissionDeniedException) {
            // Expected.
        }

        $this->assertDatabaseCount('payment_confirmations', 0);
        $this->assertDatabaseCount('audit_log', 0);
        $this->assertSame('0.00', $booking->refresh()->amount_paid);
        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
    }

    /**
     * Spec §15.12. The clerk holds `payments.confirm-cash`, but the operator
     * has not switched it on for counter staff, so holding it is not enough.
     */
    public function test_a_counter_clerk_cannot_confirm_cash_while_the_setting_is_off(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, false);

        [, $payment] = $this->bookingAwaitingDeposit([], PaymentMethodCode::Cash);

        $this->expectException(StaffPermissionDeniedException::class);

        $this->confirmations->confirm($this->clerk(), $payment, '1155.00');
    }

    public function test_a_counter_clerk_may_confirm_cash_once_the_setting_is_on(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, true);

        [, $payment] = $this->bookingAwaitingDeposit([], PaymentMethodCode::Cash);

        $result = $this->confirmations->confirm($this->clerk(), $payment, '1155.00');

        $this->assertSame(BookingStatus::Confirmed, $result->bookingStatusAfter);
    }

    public function test_a_branch_manager_confirms_cash_regardless_of_the_setting(): void
    {
        app(SettingsRepositoryContract::class)->set(SettingKey::CounterClerkMayConfirmCash, false);

        [, $payment] = $this->bookingAwaitingDeposit([], PaymentMethodCode::Cash);

        $result = $this->confirmations->confirm($this->manager(), $payment, '1155.00');

        $this->assertSame(BookingStatus::Confirmed, $result->bookingStatusAfter);
    }

    public function test_a_card_payment_cannot_be_confirmed_by_anyone(): void
    {
        // A gateway would confirm its own. There is no permission for this
        // because there is no such thing as doing it by hand.
        [, $payment] = $this->bookingAwaitingDeposit([], PaymentMethodCode::CreditCard);

        $this->expectException(PaymentMethodNotAvailableException::class);

        $this->confirmations->confirm($this->superAdmin(), $payment, '1155.00');
    }

    // --- Audit (spec §12) --------------------------------------------------

    public function test_the_confirmation_is_audited_against_the_staff_member(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->atBranch($branch)->withRole(StaffRole::BranchManager)->create();

        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $this->confirmations->confirm($manager, $payment, '1155.00', 'Seen on the statement.');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'payment.confirmed',
            'booking_id' => $booking->getKey(),
            'actor_user_id' => $manager->getKey(),
            'branch_id' => $branch->getKey(),
            'payment_reference' => $payment->payment_reference,
            'payment_method_code' => 'bank_transfer',
            'amount' => '1155.00',
            'status_before' => 'pending_payment',
            'status_after' => 'confirmed',
            'is_automatic' => false,
            'notes' => 'Seen on the statement.',
        ]);
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $bookingAttributes
     * @return array{0: Booking, 1: Payment}
     */
    private function bookingAwaitingDeposit(
        array $bookingAttributes = [],
        PaymentMethodCode $code = PaymentMethodCode::BankTransfer,
    ): array {
        $booking = Booking::factory()->create(array_merge([
            'status' => BookingStatus::PendingPayment,
            'payment_status' => BookingPaymentStatus::AwaitingPayment,
            'pay_in_full' => false,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '0.00',
            'balance_due' => '2310.00',
            'payment_method_code' => $code,
            'payment_deadline_at' => $this->now->addHours(48),
            'confirmed_at' => null,
        ], $bookingAttributes));

        $payment = Payment::factory()->forBooking($booking)->deposit()->create([
            'payment_reference' => $booking->reference.'-1',
            'payment_method_code' => $code,
            'status' => PaymentStatus::AwaitingPayment,
            'amount' => '0.00',
        ]);

        return [$booking, $payment];
    }

    /**
     * @return array{0: Booking, 1: Payment}
     */
    private function bookingAwaitingFullPayment(): array
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::PendingPayment,
            'payment_status' => BookingPaymentStatus::AwaitingPayment,
            'pay_in_full' => true,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '0.00',
            'balance_due' => '2310.00',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'payment_deadline_at' => $this->now->addHours(48),
            'confirmed_at' => null,
        ]);

        $payment = Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-1',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'status' => PaymentStatus::AwaitingPayment,
            'expected_amount' => '2310.00',
            'amount' => '0.00',
        ]);

        return [$booking, $payment];
    }

    /**
     * A booking with a real hold placed through the sanctioned writer.
     *
     * @return array{0: Booking, 1: Payment, 2: VehicleHold}
     */
    private function bookingWithHold(): array
    {
        [$booking, $payment] = $this->bookingAwaitingDeposit();

        $class = VehicleClass::factory()->create();
        $branch = Branch::factory()->create(['operator_id' => $class->operator_id]);
        $vehicle = Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);

        $hold = app(VehicleHoldServiceContract::class)->place(
            $vehicle,
            DateRange::of($this->now->addDays(10), $this->now->addDays(13)),
            $booking->payment_deadline_at,
            $booking->getKey(),
        );

        return [$booking, $payment, $hold];
    }

    private function manager(): User
    {
        return User::factory()->withRole(StaffRole::BranchManager)->create();
    }

    private function clerk(): User
    {
        return User::factory()->withRole(StaffRole::CounterClerk)->create();
    }

    private function superAdmin(): User
    {
        return User::factory()->withRole(StaffRole::SuperAdmin)->create();
    }
}
