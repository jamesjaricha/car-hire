<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RefundRequestServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\SettingKey;
use App\Enums\StaffRole;
use App\Exceptions\RefundNotApprovableException;
use App\Exceptions\RefundNotRequestableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\AuditLogEntry;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Raising and deciding refunds. Spec §9.3's first two steps.
 *
 * The two-person rule gets the most weight here, and is tested twice on
 * purpose: once through the service, and once by writing to the table directly
 * to prove the CHECK constraint holds when no service is involved. It is a
 * fraud control, and a fraud control that only exists in application code is one
 * careless method away from being absent.
 */
final class RefundRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private RefundRequestServiceContract $refunds;

    private SettingsRepositoryContract $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->settings = app(SettingsRepositoryContract::class);
        $this->settings->set(SettingKey::AdminFeeAmount, '150.00');

        $this->refunds = app(RefundRequestServiceContract::class);
    }

    // --- Requesting ---------------------------------------------------------

    public function test_it_freezes_the_calculated_figures_onto_the_refund(): void
    {
        $booking = $this->cancelledBookingHolding('2310.00');

        $refund = $this->refunds->request(
            actor: $this->clerk(),
            booking: $booking,
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::BankTransfer,
        );

        $this->assertSame('2310.00', $refund->amount_paid_at_request);
        $this->assertSame('0.00', $refund->booking_deposit_retained);
        $this->assertSame('150.00', $refund->admin_fee_configured);
        $this->assertSame('150.00', $refund->admin_fee_deducted);
        $this->assertSame('2160.00', $refund->amount);
        $this->assertSame(RefundStatus::Requested, $refund->status);
        $this->assertFalse($refund->admin_fee_was_placeholder);
    }

    /**
     * The reason the figures are frozen rather than derived on read.
     */
    public function test_a_later_change_to_the_admin_fee_does_not_move_a_raised_refund(): void
    {
        $booking = $this->cancelledBookingHolding('2310.00');

        $refund = $this->refunds->request(
            actor: $this->clerk(),
            booking: $booking,
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        $this->settings->set(SettingKey::AdminFeeAmount, '900.00');

        $this->assertSame('2160.00', $refund->refresh()->amount);
    }

    public function test_it_records_that_the_fee_was_still_a_placeholder(): void
    {
        // The seeded §15.1 state: zero, and flagged as undecided.
        $this->settings->set(SettingKey::AdminFeeAmount, '0.00', isPlaceholder: true);

        $refund = $this->refunds->request(
            actor: $this->clerk(),
            booking: $this->cancelledBookingHolding('2310.00'),
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        // Frozen on the row, so it still reads as "computed with an undecided
        // fee" after the operator enters a real one.
        $this->assertTrue($refund->admin_fee_was_placeholder);
        $this->assertSame('2310.00', $refund->amount);
    }

    public function test_it_refuses_a_staff_member_without_the_permission(): void
    {
        $nobody = User::factory()->create();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->refunds->request(
            actor: $nobody,
            booking: $this->cancelledBookingHolding('2310.00'),
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );
    }

    /**
     * A refund of nothing would sit in the approval queue asking two people to
     * sign off a payment that will never be made, and could never be disbursed
     * — §9.3 wants a reference, and there is none for money that did not move.
     */
    public function test_it_refuses_when_the_calculation_comes_to_nothing(): void
    {
        // Paid the deposit, cancelling two hours before pickup: §9.1 forfeits
        // exactly what they paid.
        $booking = $this->cancelledBookingHolding('1155.00', pickupAt: $this->now->addHours(2));

        $this->expectException(RefundNotRequestableException::class);

        $this->refunds->request(
            actor: $this->clerk(),
            booking: $booking,
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );
    }

    public function test_it_refuses_a_second_open_refund_on_the_same_booking(): void
    {
        $booking = $this->cancelledBookingHolding('2310.00');
        $clerk = $this->clerk();

        $this->refunds->request(
            actor: $clerk,
            booking: $booking,
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        // Two open refunds against one booking can pay the same money back
        // twice; the disbursement key cannot see across refunds.
        $this->expectException(RefundNotRequestableException::class);

        $this->refunds->request(
            actor: $clerk,
            booking: $booking->refresh(),
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );
    }

    public function test_a_rejected_refund_does_not_block_a_new_one(): void
    {
        $booking = $this->cancelledBookingHolding('2310.00');
        $clerk = $this->clerk();

        $first = $this->refunds->request(
            actor: $clerk,
            booking: $booking,
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        $this->refunds->reject($this->manager(), $first, 'Raised against the wrong booking.');

        $second = $this->refunds->request(
            actor: $clerk,
            booking: $booking->refresh(),
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        $this->assertSame(RefundStatus::Requested, $second->status);
    }

    public function test_requesting_leaves_the_money_where_it_is(): void
    {
        $booking = $this->cancelledBookingHolding('2310.00');

        $this->refunds->request(
            actor: $this->clerk(),
            booking: $booking,
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        // Nothing has been agreed and nothing has moved.
        $this->assertSame('2310.00', $booking->refresh()->amount_paid);
    }

    // --- Approving ----------------------------------------------------------

    public function test_a_manager_can_approve_a_refund_somebody_else_raised(): void
    {
        $refund = $this->requestedRefund();
        $manager = $this->manager();

        $approved = $this->refunds->approve($manager, $refund);

        $this->assertSame(RefundStatus::Approved, $approved->status);
        $this->assertSame((int) $manager->getKey(), (int) $approved->approved_by_user_id);
        $this->assertTrue($approved->approved_at->equalTo($this->now));
    }

    /**
     * SPEC §9.3. The whole point of the two middle states.
     */
    public function test_the_same_person_cannot_approve_their_own_request(): void
    {
        $manager = $this->manager();

        $refund = $this->refunds->request(
            actor: $manager,
            booking: $this->cancelledBookingHolding('2310.00'),
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );

        $this->expectException(RefundNotApprovableException::class);

        $this->refunds->approve($manager, $refund);
    }

    /**
     * The same rule, with no service involved.
     *
     * `RefundRequestService::approve()` refuses this politely. If somebody
     * later writes a second approval path — a bulk action, a console command, a
     * migration fixing up data — the database still refuses it. That is what
     * makes this a control rather than a convention.
     */
    public function test_the_database_itself_refuses_an_approver_who_is_the_requester(): void
    {
        $refund = $this->requestedRefund();

        $this->expectException(QueryException::class);

        DB::table('refunds')
            ->where('id', $refund->getKey())
            ->update([
                'status' => RefundStatus::Approved->value,
                'approved_by_user_id' => $refund->requested_by_user_id,
                'approved_at' => $this->now->toDateTimeString(),
            ]);
    }

    public function test_a_counter_clerk_may_not_approve(): void
    {
        // §12: request is everyone, approve is Branch Manager and above.
        $refund = $this->requestedRefund();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->refunds->approve($this->clerk(), $refund);
    }

    public function test_an_approved_refund_cannot_be_approved_again(): void
    {
        $refund = $this->requestedRefund();

        $this->refunds->approve($this->manager(), $refund);

        $this->expectException(RefundNotApprovableException::class);

        $this->refunds->approve($this->manager(), $refund->refresh());
    }

    /**
     * Approval moves the booking to §7.1's `refund_pending`, which is the state
     * that says: we have agreed to give this money back and we still have it.
     */
    public function test_approval_moves_the_booking_to_refund_pending(): void
    {
        $refund = $this->requestedRefund();

        $this->refunds->approve($this->manager(), $refund);

        // Fetched rather than read through the relation: Model::shouldBeStrict()
        // refuses lazy loading outside production.
        $booking = Booking::query()->findOrFail($refund->booking_id);

        $this->assertSame(BookingPaymentStatus::RefundPending, $booking->payment_status);
        // Still holding it. Only disbursement takes it away.
        $this->assertSame('2310.00', $booking->amount_paid);
    }

    public function test_it_audits_the_approval(): void
    {
        $refund = $this->requestedRefund();
        $manager = $this->manager();

        $this->refunds->approve($manager, $refund);

        $entry = AuditLogEntry::query()
            ->where('action', 'refund.approved')
            ->where('entity_id', $refund->getKey())
            ->firstOrFail();

        $this->assertSame('Refund', $entry->entity);
        $this->assertSame((int) $manager->getKey(), (int) $entry->actor_user_id);
        $this->assertSame('2160.00', $entry->amount);
        $this->assertSame(
            (int) $refund->requested_by_user_id,
            $entry->metadata['requested_by_user_id'],
        );
    }

    // --- Rejecting ----------------------------------------------------------

    public function test_a_manager_can_reject_with_a_reason(): void
    {
        $refund = $this->requestedRefund();
        $manager = $this->manager();

        $rejected = $this->refunds->reject($manager, $refund, 'Outside the terms the customer accepted.');

        $this->assertSame(RefundStatus::Rejected, $rejected->status);
        $this->assertSame('Outside the terms the customer accepted.', $rejected->rejection_reason);
        $this->assertSame((int) $manager->getKey(), (int) $rejected->rejected_by_user_id);
    }

    public function test_a_rejection_without_a_reason_is_refused(): void
    {
        $refund = $this->requestedRefund();

        $this->expectException(RefundNotApprovableException::class);

        $this->refunds->reject($this->manager(), $refund, '   ');
    }

    /**
     * Rejecting the refund decides that no money goes back. It does not undo the
     * cancellation — the hire is still off.
     */
    public function test_rejecting_leaves_the_booking_cancelled(): void
    {
        $refund = $this->requestedRefund();

        $this->refunds->reject($this->manager(), $refund, 'Not eligible.');

        $this->assertSame(
            BookingStatus::CancelledByCustomer,
            Booking::query()->findOrFail($refund->booking_id)->status,
        );
    }

    public function test_it_audits_the_rejection(): void
    {
        $refund = $this->requestedRefund();

        $this->refunds->reject($this->manager(), $refund, 'Not eligible.');

        // §12 names "refund request and approval" and not rejection, but §9.3
        // requires every state change to be audited — and this is the outcome a
        // customer is most likely to dispute.
        $this->assertDatabaseHas('audit_log', [
            'action' => 'refund.rejected',
            'entity' => 'Refund',
            'entity_id' => $refund->getKey(),
        ]);
    }

    // --- Fixtures -----------------------------------------------------------

    private function cancelledBookingHolding(string $amountPaid, ?CarbonImmutable $pickupAt = null): Booking
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::CancelledByCustomer,
            'payment_status' => BookingPaymentStatus::PartiallyPaid,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => $amountPaid,
            'balance_due' => '0.00',
            'pickup_at' => $pickupAt ?? $this->now->addDays(5),
            'cancelled_at' => $this->now,
        ]);

        // The money that is actually being refunded. The ledger sums these, so
        // a booking whose amount_paid was merely typed into the fixture would
        // have its figure recomputed away on the first approval.
        Payment::factory()->forBooking($booking)->confirmed($amountPaid)->create([
            'payment_reference' => $booking->reference.'-1',
            'expected_amount' => $amountPaid,
        ]);

        return $booking;
    }

    private function requestedRefund(): Refund
    {
        return $this->refunds->request(
            actor: $this->clerk(),
            booking: $this->cancelledBookingHolding('2310.00'),
            reason: RefundReason::CustomerCancellation,
            method: PaymentMethodCode::Cash,
        );
    }

    private function clerk(): User
    {
        return User::factory()->withRole(StaffRole::CounterClerk)->create();
    }

    private function manager(): User
    {
        return User::factory()->withRole(StaffRole::BranchManager)->create();
    }
}
