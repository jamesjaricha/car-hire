<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentRecordingServiceContract;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\StaffRole;
use App\Exceptions\PaymentNotRecordableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentRecordingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentRecordingServiceContract $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->recording = app(PaymentRecordingServiceContract::class);
    }

    // --- Raising the receipt a booking waits on --------------------------

    public function test_it_raises_a_receipt_awaiting_payment(): void
    {
        $booking = Booking::factory()->create([
            'pay_in_full' => false,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
        ]);

        $payment = $this->recording->raiseForBooking($booking, $this->method(PaymentMethodCode::BankTransfer));

        $this->assertSame(PaymentStatus::AwaitingPayment, $payment->status);
        $this->assertSame($booking->getKey(), $payment->booking_id);
        $this->assertSame($booking->operator_id, $payment->operator_id);
        $this->assertSame($booking->reference.'-1', $payment->payment_reference);
        $this->assertSame('ZMW', $payment->currency);
    }

    public function test_nothing_has_arrived_when_a_receipt_is_raised(): void
    {
        // A receipt raised with its expected figure already in `amount` would
        // look, to every later query, exactly like money that was received.
        $booking = Booking::factory()->create(['pay_in_full' => true, 'grand_total' => '2310.00']);

        $payment = $this->recording->raiseForBooking($booking, $this->method(PaymentMethodCode::Cash));

        $this->assertSame('0.00', $payment->amount);
        $this->assertSame('2310.00', $payment->expected_amount);
        $this->assertFalse($payment->hasShortfall());
    }

    public function test_the_expected_amount_follows_the_customers_choice(): void
    {
        $deposit = Booking::factory()->create([
            'pay_in_full' => false,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
        ]);

        $full = Booking::factory()->create([
            'pay_in_full' => true,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
        ]);

        $method = $this->method(PaymentMethodCode::BankTransfer);

        $depositPayment = $this->recording->raiseForBooking($deposit, $method);
        $fullPayment = $this->recording->raiseForBooking($full, $method);

        $this->assertTrue($depositPayment->is_deposit);
        $this->assertSame('1155.00', $depositPayment->expected_amount);

        $this->assertFalse($fullPayment->is_deposit);
        $this->assertSame('2310.00', $fullPayment->expected_amount);
    }

    public function test_a_second_receipt_on_the_same_booking_takes_the_next_reference(): void
    {
        $booking = Booking::factory()->create();
        $method = $this->method(PaymentMethodCode::Cash);

        $first = $this->recording->raiseForBooking($booking, $method);
        $second = $this->recording->raiseForBooking($booking, $method);

        $this->assertSame($booking->reference.'-1', $first->payment_reference);
        $this->assertSame($booking->reference.'-2', $second->payment_reference);
    }

    /**
     * Recording is not receiving. `amount_paid`, `balance_due` and
     * `payment_status` are recomputed from CONFIRMED receipts only, together,
     * by the confirmation service — a helpful update from here would be exactly
     * the second writer that makes that guarantee untrue.
     */
    public function test_raising_a_receipt_does_not_move_the_bookings_payment_position(): void
    {
        $booking = Booking::factory()->create([
            'amount_paid' => '0.00',
            'balance_due' => '2310.00',
            'grand_total' => '2310.00',
        ]);

        $this->recording->raiseForBooking($booking, $this->method(PaymentMethodCode::Cash));

        $booking->refresh();

        $this->assertSame('0.00', $booking->amount_paid);
        $this->assertSame('2310.00', $booking->balance_due);
    }

    // --- Money that arrived without a booking ----------------------------

    public function test_it_records_an_unattributed_receipt(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $payment = $this->recording->recordUnmatchedReceipt(
            actor: $manager,
            code: PaymentMethodCode::MtnMomo,
            amount: '1155.00',
            externalReference: 'MP240815.1423.A54321',
            notes: 'Seen on the MTN statement, no booking reference given.',
        );

        $this->assertSame('UP-00001', $payment->payment_reference);
        $this->assertNull($payment->booking_id);
        $this->assertNull($payment->operator_id);
        $this->assertSame('1155.00', $payment->amount);
        $this->assertSame(PaymentStatus::AwaitingPayment, $payment->status);
        $this->assertSame('MP240815.1423.A54321', $payment->external_reference);
        $this->assertSame($manager->getKey(), $payment->recorded_by_user_id);
    }

    /**
     * No booking means nothing was ever asked for, so an unattributed receipt
     * cannot be short. It is money nobody has attributed, not money missing.
     */
    public function test_an_unattributed_receipt_has_no_expectation_and_no_shortfall(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $payment = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::AirtelMoney, '50.00');

        $this->assertNull($payment->expected_amount);
        $this->assertFalse($payment->hasShortfall());
        $this->assertSame('0.00', $payment->shortfallAmount());
    }

    public function test_an_unattributed_receipt_appears_in_the_unmatched_queue(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '400.00');
        Payment::factory()->create();

        $this->assertSame(1, Payment::query()->unmatched()->count());
    }

    public function test_recording_an_unattributed_receipt_is_audited_against_the_staff_member(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->recording->recordUnmatchedReceipt(
            $manager,
            PaymentMethodCode::MtnMomo,
            '400.00',
            'MP240815.1423.A54321',
        );

        $this->assertDatabaseHas('audit_log', [
            'action' => 'payment.recorded',
            'actor_user_id' => $manager->getKey(),
            'payment_reference' => 'UP-00001',
            'payment_method_code' => 'mtn_momo',
            'amount' => '400.00',
            'booking_id' => null,
            'is_automatic' => false,
        ]);
    }

    // --- Refusals ---------------------------------------------------------

    public function test_a_staff_member_without_the_permission_may_not_key_in_a_payment(): void
    {
        // Spec §12 has no "record a payment" permission; the closest it offers
        // is payments.edit-manual-payment, which counter clerks do not hold.
        // Recorded in OPEN-ITEMS.md as a judgement call.
        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->recording->recordUnmatchedReceipt($clerk, PaymentMethodCode::Cash, '100.00');
    }

    public function test_a_refused_recording_writes_nothing(): void
    {
        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();

        try {
            $this->recording->recordUnmatchedReceipt($clerk, PaymentMethodCode::Cash, '100.00');
            $this->fail('The recording should have been refused.');
        } catch (StaffPermissionDeniedException) {
            // Expected.
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_a_zero_amount_is_refused(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->expectException(PaymentNotRecordableException::class);

        $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::Cash, '0.00');
    }

    public function test_a_negative_amount_is_refused(): void
    {
        // Money already taken is reversed by raising a refund, not by writing a
        // negative receipt that would quietly reduce a total somewhere.
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->expectException(PaymentNotRecordableException::class);

        $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::Cash, '-500.00');
    }

    public function test_a_card_payment_cannot_be_keyed_in_by_hand(): void
    {
        // There is no adapter for it, and money recorded against a card that
        // never went through a gateway is untraceable to any settlement.
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        $this->expectException(PaymentNotRecordableException::class);

        $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::CreditCard, '500.00');
    }

    private function method(PaymentMethodCode $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code->value)->sole();
    }
}
