<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentRecordingServiceContract;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\StaffPermission;
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

    /**
     * The person at the till is the person who sees money arrive. Making them
     * wait for a manager to write it down is how a receipt ends up on a note
     * beside the till instead of in the system.
     *
     * Guarded by `payments.record-manual`, which is not in spec §12 — see the
     * note on its declaration for why it had to be invented rather than
     * borrowed from `payments.edit-manual-payment`.
     */
    public function test_a_counter_clerk_may_record_money_that_arrived(): void
    {
        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();

        $payment = $this->recording->recordUnmatchedReceipt($clerk, PaymentMethodCode::Cash, '100.00');

        $this->assertSame('UP-00001', $payment->payment_reference);
        $this->assertSame($clerk->getKey(), $payment->recorded_by_user_id);
    }

    /**
     * Recording what arrived and altering what was already recorded are
     * separate powers. A clerk holds the first and not the second, which is the
     * whole reason the permission was split.
     */
    public function test_a_counter_clerk_still_cannot_edit_a_recorded_payment(): void
    {
        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();

        $this->assertTrue($clerk->hasPermissionTo(StaffPermission::PaymentsRecordManual));
        $this->assertFalse($clerk->hasPermissionTo(StaffPermission::PaymentsEditManualPayment));
    }

    public function test_a_staff_member_without_the_permission_may_not_key_in_a_payment(): void
    {
        $nobody = User::factory()->create();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->recording->recordUnmatchedReceipt($nobody, PaymentMethodCode::Cash, '100.00');
    }

    public function test_a_refused_recording_writes_nothing(): void
    {
        $nobody = User::factory()->create();

        try {
            $this->recording->recordUnmatchedReceipt($nobody, PaymentMethodCode::Cash, '100.00');
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

    // --- Attributing an unmatched receipt --------------------------------

    public function test_it_attributes_a_receipt_to_a_booking(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $booking = Booking::factory()->create();

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');

        $matched = $this->recording->matchToBooking($manager, $receipt, $booking);

        $this->assertSame($booking->getKey(), $matched->booking_id);
        $this->assertSame($booking->operator_id, $matched->operator_id);
        $this->assertSame($manager->getKey(), $matched->matched_by_user_id);
        $this->assertNotNull($matched->matched_at);
    }

    /**
     * The number written down when the money appeared must still find it
     * afterwards. Renumbering it into the booking's series would erase the only
     * reference the customer and the statement have in common.
     */
    public function test_a_matched_receipt_keeps_its_own_reference(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $booking = Booking::factory()->create();

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');
        $matched = $this->recording->matchToBooking($manager, $receipt, $booking);

        $this->assertSame('UP-00001', $matched->payment_reference);
    }

    /**
     * Nothing was ever asked for on this receipt. Back-filling the booking's
     * balance as an expectation would invent a shortfall out of a figure the
     * customer was never quoted.
     */
    public function test_matching_does_not_invent_an_expected_amount(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $booking = Booking::factory()->create(['grand_total' => '2310.00']);

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '400.00');
        $matched = $this->recording->matchToBooking($manager, $receipt, $booking);

        $this->assertNull($matched->expected_amount);
        $this->assertFalse($matched->hasShortfall());
    }

    /**
     * Attribution is not verification. Two different judgements, and having
     * just made the first should not make the second happen by itself.
     */
    public function test_matching_does_not_confirm_anything(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $booking = Booking::factory()->create(['amount_paid' => '0.00']);

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');
        $matched = $this->recording->matchToBooking($manager, $receipt, $booking);

        $this->assertSame(PaymentStatus::AwaitingPayment, $matched->status);
        $this->assertDatabaseCount('payment_confirmations', 0);
        $this->assertSame('0.00', $booking->refresh()->amount_paid);
    }

    public function test_a_receipt_that_already_belongs_to_a_booking_cannot_be_rematched(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $first = Booking::factory()->create();
        $second = Booking::factory()->create();

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');
        $this->recording->matchToBooking($manager, $receipt, $first);

        $this->expectException(PaymentNotRecordableException::class);

        $this->recording->matchToBooking($manager, $receipt->refresh(), $second);
    }

    public function test_a_counter_clerk_may_attribute_a_receipt(): void
    {
        // Same permission as recording one: a clerk who can write the money
        // down can say whose it is. Both are statements about what arrived,
        // neither alters a figure anyone has relied on.
        $clerk = User::factory()->withRole(StaffRole::CounterClerk)->create();
        $booking = Booking::factory()->create();

        $receipt = $this->recording->recordUnmatchedReceipt($clerk, PaymentMethodCode::MtnMomo, '1155.00');
        $matched = $this->recording->matchToBooking($clerk, $receipt, $booking);

        $this->assertSame($booking->getKey(), $matched->booking_id);
        $this->assertSame($clerk->getKey(), $matched->matched_by_user_id);
    }

    /**
     * Found by the pre-merge audit. Refused at both steps: attaching money to a
     * cancelled booking and only discovering at the confirm step that it cannot
     * be taken leaves the receipt attributed to a booking nobody will honour,
     * which is a worse place for it than the queue it came from.
     */
    public function test_a_receipt_cannot_be_attributed_to_a_cancelled_booking(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::CancelledNonPayment]);

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');

        $this->expectException(PaymentNotRecordableException::class);

        $this->recording->matchToBooking($manager, $receipt, $booking);
    }

    public function test_someone_with_no_role_may_not_attribute_a_receipt(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $nobody = User::factory()->create();
        $booking = Booking::factory()->create();

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');

        $this->expectException(StaffPermissionDeniedException::class);

        $this->recording->matchToBooking($nobody, $receipt, $booking);
    }

    public function test_attributing_a_receipt_is_audited(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $booking = Booking::factory()->create();

        $receipt = $this->recording->recordUnmatchedReceipt($manager, PaymentMethodCode::MtnMomo, '1155.00');
        $this->recording->matchToBooking($manager, $receipt, $booking);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'payment.matched',
            'booking_id' => $booking->getKey(),
            'actor_user_id' => $manager->getKey(),
            'payment_reference' => 'UP-00001',
            'amount' => '1155.00',
        ]);
    }

    private function method(PaymentMethodCode $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code->value)->sole();
    }
}
