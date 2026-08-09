<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RefundDisbursementServiceContract;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\StaffRole;
use App\Exceptions\RefundNotDisbursableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\AuditLogEntry;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDisbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money leaving. Spec §9.3's third step.
 *
 * Two things are load-bearing: it can only happen once, and it changes what the
 * booking has been paid. The single-process suite proves the second and the
 * ordinary case of the first; `RefundDisbursementConcurrencyTest` proves the
 * first under real contention, which is the only way to prove it.
 */
final class RefundDisbursementServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private RefundDisbursementServiceContract $disbursements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->disbursements = app(RefundDisbursementServiceContract::class);
    }

    // --- The happy path -----------------------------------------------------

    public function test_it_records_the_payout_with_its_reference(): void
    {
        [$booking, $refund] = $this->approvedRefund();
        $manager = $this->manager();

        $result = $this->disbursements->disburse($manager, $refund, 'MM-4471', 'Sent to the number on file.');

        $this->assertSame(RefundStatus::Disbursed, $result->refund->status);
        $this->assertSame('MM-4471', $result->disbursement->disbursement_reference);
        $this->assertSame('2160.00', $result->disbursement->amount_disbursed);
        $this->assertSame((int) $manager->getKey(), (int) $result->disbursement->disbursed_by_user_id);
        $this->assertTrue($result->disbursement->disbursed_at->equalTo($this->now));
    }

    /**
     * The ledger, doing what the whole design exists for: `amount_paid` is
     * confirmed receipts minus disbursed refunds, so the money leaving reduces
     * what the booking is recorded as having been paid.
     */
    public function test_disbursing_reduces_what_the_booking_has_been_paid(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        $result = $this->disbursements->disburse($this->manager(), $refund, 'MM-4471');

        // K2,310 arrived, K2,160 went back. The K150 admin fee stays.
        $this->assertSame('150.00', $result->amountPaid);
        $this->assertSame('150.00', $booking->refresh()->amount_paid);
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
    }

    /**
     * The original receipt is not rewritten.
     *
     * A confirmed payment records that money genuinely arrived, on a date,
     * verified by a named person against a bank line. That remains true after a
     * refund. Flipping it to `refunded` would destroy the only record of an
     * event that still happened, and leave the month it fell in unreconcilable.
     */
    public function test_the_original_receipt_keeps_its_status_and_its_amount(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        $payment = Payment::query()->where('booking_id', $booking->getKey())->firstOrFail();

        $this->disbursements->disburse($this->manager(), $refund, 'MM-4471');

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('2310.00', $payment->amount);
    }

    // --- Once, and only once ------------------------------------------------

    public function test_a_refund_cannot_be_disbursed_twice(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        $this->disbursements->disburse($this->manager(), $refund, 'MM-4471');

        $this->expectException(RefundNotDisbursableException::class);

        $this->disbursements->disburse($this->manager(), $refund->refresh(), 'MM-9999');
    }

    /**
     * The guarantee, with no service involved.
     *
     * The service checks under a lock and refuses politely. This proves the
     * database refuses it regardless — which is what makes §9.3's "never
     * disbursed twice" structural rather than a habit.
     */
    public function test_the_database_itself_refuses_a_second_disbursement_row(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        RefundDisbursement::factory()->forRefund($refund)->disbursedBy($this->manager())->create();

        $this->expectException(UniqueConstraintViolationException::class);

        RefundDisbursement::factory()->forRefund($refund)->disbursedBy($this->manager())->create();
    }

    public function test_only_one_disbursement_row_exists_after_a_repeated_attempt(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        $this->disbursements->disburse($this->manager(), $refund, 'MM-4471');

        try {
            $this->disbursements->disburse($this->manager(), $refund->refresh(), 'MM-9999');
        } catch (RefundNotDisbursableException) {
            // Expected.
        }

        $this->assertSame(
            1,
            RefundDisbursement::query()->where('refund_id', $refund->getKey())->count(),
        );

        // And the money only left once.
        $this->assertSame('150.00', $booking->refresh()->amount_paid);
    }

    // --- What it refuses ----------------------------------------------------

    public function test_an_unapproved_refund_cannot_be_paid_out(): void
    {
        [$booking, $refund] = $this->approvedRefund(approve: false);

        // §9.3 puts a second person between the request and the money.
        $this->expectException(RefundNotDisbursableException::class);

        $this->disbursements->disburse($this->manager(), $refund, 'MM-4471');
    }

    /**
     * Spec §9.3 requires proof of disbursement. If there is nothing to type,
     * the money has not actually left.
     */
    public function test_it_refuses_a_blank_disbursement_reference(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        $this->expectException(RefundNotDisbursableException::class);

        $this->disbursements->disburse($this->manager(), $refund, '   ');
    }

    public function test_a_counter_clerk_may_not_pay_a_refund_out(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->disbursements->disburse($this->clerk(), $refund, 'MM-4471');
    }

    public function test_nothing_is_written_when_it_refuses(): void
    {
        [$booking, $refund] = $this->approvedRefund();

        try {
            $this->disbursements->disburse($this->clerk(), $refund, 'MM-4471');
        } catch (StaffPermissionDeniedException) {
            // Expected.
        }

        $this->assertDatabaseCount('refund_disbursements', 0);
        $this->assertSame(RefundStatus::Approved, $refund->refresh()->status);
        $this->assertSame('2310.00', $booking->refresh()->amount_paid);
    }

    // --- The trail ----------------------------------------------------------

    public function test_it_audits_the_disbursement_with_the_reference(): void
    {
        [$booking, $refund] = $this->approvedRefund();
        $manager = $this->manager();

        $this->disbursements->disburse($manager, $refund, 'MM-4471');

        $entry = AuditLogEntry::query()
            ->where('action', 'refund.disbursed')
            ->where('entity_id', $refund->getKey())
            ->firstOrFail();

        $this->assertSame((int) $manager->getKey(), (int) $entry->actor_user_id);
        $this->assertSame('2160.00', $entry->amount);
        // The field somebody will be asked for when a customer says the money
        // never arrived.
        $this->assertSame('MM-4471', $entry->payment_reference);
        $this->assertSame('150.00', $entry->metadata['amount_paid_after']);
        $this->assertFalse((bool) $entry->is_automatic);
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * A cancelled booking holding K2,310, with a K2,160 refund raised by a clerk
     * and — unless told otherwise — approved by a manager.
     *
     * @return array{0: Booking, 1: Refund}
     */
    private function approvedRefund(bool $approve = true): array
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::CancelledByCustomer,
            'payment_status' => BookingPaymentStatus::PaidInFull,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '2310.00',
            'balance_due' => '0.00',
            'pickup_at' => $this->now->addDays(5),
            'cancelled_at' => $this->now,
        ]);

        Payment::factory()->forBooking($booking)->confirmed('2310.00')->create([
            'payment_reference' => $booking->reference.'-1',
            'expected_amount' => '2310.00',
        ]);

        $refund = Refund::factory()
            ->forBooking($booking)
            ->requestedBy($this->clerk())
            ->state(['method' => PaymentMethodCode::MtnMomo])
            ->create();

        if ($approve) {
            $refund->forceFill([
                'status' => RefundStatus::Approved,
                'approved_by_user_id' => $this->manager()->getKey(),
                'approved_at' => $this->now,
            ])->save();
        }

        return [$booking, $refund];
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
