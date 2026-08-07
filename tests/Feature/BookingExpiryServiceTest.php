<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BookingExpiryServiceContract;
use App\Contracts\PaymentConfirmationServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\StaffRole;
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

final class BookingExpiryServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingExpiryServiceContract $expiry;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->expiry = app(BookingExpiryServiceContract::class);
    }

    // --- The ordinary path -----------------------------------------------

    public function test_an_unpaid_booking_is_cancelled_when_its_deadline_passes(): void
    {
        [$booking] = $this->unpaidBookingPastDeadline();

        $result = $this->expiry->sweep();

        $this->assertSame(1, $result->expired);

        $booking->refresh();

        $this->assertSame(BookingStatus::CancelledNonPayment, $booking->status);
        $this->assertSame(BookingPaymentStatus::PaymentExpired, $booking->payment_status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertNotNull($booking->cancellation_reason);
    }

    public function test_its_outstanding_payment_expires_with_it(): void
    {
        [, $payment] = $this->unpaidBookingPastDeadline();

        $this->expiry->sweep();

        $this->assertSame(PaymentStatus::PaymentExpired, $payment->refresh()->status);
    }

    /**
     * Spec §8.4: the hold is released immediately. A vehicle held against a
     * cancelled booking is inventory nobody can sell.
     */
    public function test_the_hold_is_released_immediately(): void
    {
        [$booking, , $hold] = $this->unpaidBookingPastDeadline(withHold: true);

        $this->expiry->sweep();

        $hold->refresh();

        $this->assertNotNull($hold->released_at);
        $this->assertSame(0, VehicleHold::query()->where('booking_id', $booking->getKey())->stillClaiming()->count());
    }

    public function test_a_booking_still_inside_its_deadline_is_untouched(): void
    {
        $booking = $this->booking(['payment_deadline_at' => $this->now->addHours(6)]);

        $result = $this->expiry->sweep();

        $this->assertSame(0, $result->expired);
        $this->assertSame(BookingStatus::PendingPayment, $booking->refresh()->status);
    }

    /**
     * Spec §8.2 places no hold and sets no deadline for these, so there is no
     * window they can fail to pay within. They are settled at the counter.
     */
    public function test_a_short_notice_booking_is_never_expired(): void
    {
        $booking = $this->booking([
            'is_short_notice' => true,
            'payment_deadline_at' => null,
        ]);

        $this->expiry->sweep();

        $this->assertSame(BookingStatus::PendingPayment, $booking->refresh()->status);
    }

    public function test_a_confirmed_booking_is_never_expired(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::Confirmed,
            'payment_deadline_at' => $this->now->subHour(),
        ]);

        $this->expiry->sweep();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    // --- Money the sweep refuses to touch ---------------------------------

    /**
     * The case spec §8.4 does not cover. A customer who confirmed less than
     * they chose to pay leaves the booking pending AND holding their money.
     * Cancelling that unattended strands real cash against a cancelled booking,
     * and spec §9.3 wants two people on any refund — neither of them a cron.
     */
    public function test_a_part_paid_booking_is_left_for_staff(): void
    {
        $booking = $this->booking([
            'payment_deadline_at' => $this->now->subHour(),
            'amount_paid' => '500.00',
            'balance_due' => '1810.00',
            'payment_status' => BookingPaymentStatus::PartiallyPaid,
        ]);

        $result = $this->expiry->sweep();

        $this->assertSame(0, $result->expired);
        $this->assertSame(1, $result->leftForStaff);

        $booking->refresh();

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertNull($booking->cancelled_at);
        $this->assertSame('500.00', $booking->amount_paid);
    }

    public function test_part_paid_bookings_past_their_deadline_form_a_queue(): void
    {
        // Without a screen these accumulate silently, each holding money.
        $this->booking([
            'payment_deadline_at' => $this->now->subHour(),
            'amount_paid' => '500.00',
        ]);
        $this->booking(['payment_deadline_at' => $this->now->subHour()]);
        $this->booking(['payment_deadline_at' => $this->now->addDay()]);

        $this->assertSame(1, Booking::query()->stalledAfterDeadline()->count());
    }

    // --- The race the sweep is built around -------------------------------

    /**
     * A staff member confirming at 14:59:59 and the sweep running at 15:00:00.
     *
     * BE CLEAR ABOUT WHAT THIS PROVES. It proves the candidate query excludes a
     * booking that was confirmed before the sweep began — real, and worth
     * pinning, but the easy half.
     *
     * It does NOT prove the re-check under the lock, because a single process
     * cannot stage the interleaving that check exists for: a confirmation
     * committing after the candidate query has run and before the cancellation
     * takes its lock. That window is why every condition is tested again inside
     * expireOne() rather than trusted from the candidate list.
     *
     * If this ever needs proving rather than reasoning about, it wants a fourth
     * multi-process harness — sweep in one process, confirm in another, both
     * released at a barrier. The three that exist all earned their keep by
     * finding something, so that is not an idle suggestion.
     */
    public function test_a_booking_confirmed_before_the_sweep_is_not_a_candidate(): void
    {
        [$booking, $payment] = $this->unpaidBookingPastDeadline();

        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        app(PaymentConfirmationServiceContract::class)->confirm($manager, $payment, '1155.00');

        $result = $this->expiry->sweep();

        $this->assertSame(0, $result->expired);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
    }

    // --- Idempotence and the safety net -----------------------------------

    /**
     * `leftForStaff` counts only bookings holding money, not every skip.
     *
     * WHAT THIS PROVES, AND WHAT IT CANNOT. A paid booking is excluded by the
     * candidate query itself, so this passes with or without the three-outcome
     * change that prompted it — it pins the reported figure, not the fix.
     *
     * The mis-count the pre-merge audit found is only reachable when a booking
     * passes the candidate query and then fails a re-check under the lock,
     * which is the confirm-at-14:59:59 race a single process cannot stage. The
     * distinction matters because DEPLOYMENT.md tells somebody to read this
     * number nightly, and a signal that cries wolf is worse than no signal.
     */
    public function test_only_bookings_holding_money_are_reported_as_needing_staff(): void
    {
        [, $payment] = $this->unpaidBookingPastDeadline();

        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        app(PaymentConfirmationServiceContract::class)->confirm($manager, $payment, '1155.00');

        $result = $this->expiry->sweep();

        $this->assertSame(0, $result->expired);
        $this->assertSame(0, $result->leftForStaff);
    }

    public function test_sweeping_twice_changes_nothing_the_second_time(): void
    {
        $this->unpaidBookingPastDeadline();

        $first = $this->expiry->sweep();
        $second = $this->expiry->sweep();

        $this->assertSame(1, $first->expired);
        $this->assertSame(0, $second->expired);
        $this->assertSame(1, Booking::query()->where('status', BookingStatus::CancelledNonPayment)->count());
    }

    /**
     * The guideline's safety net: a lapsed hold whose booking was dealt with by
     * some other route would otherwise keep a vehicle off sale for as long as
     * the row survived.
     */
    public function test_it_releases_lapsed_holds_that_belong_to_no_expiring_booking(): void
    {
        $vehicle = $this->vehicle();

        $hold = app(VehicleHoldServiceContract::class)->place(
            $vehicle,
            DateRange::of($this->now->addDays(10), $this->now->addDays(13)),
            $this->now->addHour(),
        );

        $this->travelTo($this->now->addHours(2));

        $result = $this->expiry->sweep();

        $this->assertSame(1, $result->holdsReleased);
        $this->assertNotNull($hold->refresh()->released_at);
    }

    public function test_a_quiet_sweep_reports_that_it_did_nothing(): void
    {
        $this->assertTrue($this->expiry->sweep()->didNothing());
    }

    // --- Audit (spec §12) --------------------------------------------------

    public function test_the_cancellation_is_audited_as_automatic(): void
    {
        [$booking] = $this->unpaidBookingPastDeadline();

        $this->expiry->sweep();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'booking.cancelled-non-payment',
            'booking_id' => $booking->getKey(),
            'actor_user_id' => null,
            'status_before' => 'pending_payment',
            'status_after' => 'cancelled_non_payment',
            // Nobody decided this. The clock did.
            'is_automatic' => true,
        ]);
    }

    public function test_the_expired_payment_is_audited_too(): void
    {
        [$booking, $payment] = $this->unpaidBookingPastDeadline();

        $this->expiry->sweep();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'payment.expired',
            'booking_id' => $booking->getKey(),
            'payment_reference' => $payment->payment_reference,
            'status_after' => 'payment_expired',
            'is_automatic' => true,
        ]);
    }

    // --- The command -------------------------------------------------------

    public function test_the_command_runs_the_sweep(): void
    {
        [$booking] = $this->unpaidBookingPastDeadline();

        $this->artisan('carhire:expire-bookings')->assertSuccessful();

        $this->assertSame(BookingStatus::CancelledNonPayment, $booking->refresh()->status);
    }

    public function test_the_command_warns_about_bookings_holding_money(): void
    {
        $this->booking([
            'payment_deadline_at' => $this->now->subHour(),
            'amount_paid' => '500.00',
        ]);

        $this->artisan('carhire:expire-bookings')
            ->expectsOutputToContain('need a decision from staff')
            ->assertSuccessful();
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * @return array{0: Booking, 1: Payment, 2: VehicleHold|null}
     */
    private function unpaidBookingPastDeadline(bool $withHold = false): array
    {
        $booking = $this->booking(['payment_deadline_at' => $this->now->subHour()]);

        $payment = Payment::factory()->forBooking($booking)->deposit()->create([
            'payment_reference' => $booking->reference.'-1',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'status' => PaymentStatus::AwaitingPayment,
            'amount' => '0.00',
        ]);

        $hold = null;

        if ($withHold) {
            $hold = app(VehicleHoldServiceContract::class)->place(
                $this->vehicle(),
                DateRange::of($this->now->addDays(10), $this->now->addDays(13)),
                // Placed before the deadline lapsed, as it would have been.
                $this->now->addDay(),
                $booking->getKey(),
            );
        }

        return [$booking, $payment, $hold];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'status' => BookingStatus::PendingPayment,
            'payment_status' => BookingPaymentStatus::AwaitingPayment,
            'pay_in_full' => false,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => '0.00',
            'balance_due' => '2310.00',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'cancelled_at' => null,
            'confirmed_at' => null,
        ], $attributes));
    }

    private function vehicle(): Vehicle
    {
        $class = VehicleClass::factory()->create();
        $branch = Branch::factory()->create(['operator_id' => $class->operator_id]);

        return Vehicle::factory()->create([
            'operator_id' => $class->operator_id,
            'vehicle_class_id' => $class->getKey(),
            'branch_id' => $branch->getKey(),
        ]);
    }
}
