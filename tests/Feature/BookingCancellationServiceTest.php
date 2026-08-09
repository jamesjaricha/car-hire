<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BookingCancellationServiceContract;
use App\Enums\BookingStatus;
use App\Enums\StaffRole;
use App\Exceptions\InvalidBookingTransitionException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\AuditLogEntry;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ending a booking by hand.
 *
 * The part worth the most attention is the hold release. A cancelled booking
 * that keeps its vehicle claimed is not a visible failure — no exception, no
 * wrong number on a screen — it is simply a car that stops appearing in search
 * results until a date nobody is watching. That is why it survived three phases
 * as an open item, and why it is tested here rather than assumed.
 */
final class BookingCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private BookingCancellationServiceContract $cancellations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cancellations = app(BookingCancellationServiceContract::class);
    }

    public function test_it_cancels_a_pending_booking_and_stamps_the_reason(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::PendingPayment]);

        $cancelled = $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
            reason: 'Customer rang to cancel.',
        );

        $this->assertSame(BookingStatus::CancelledByCustomer, $cancelled->status);
        $this->assertSame('Customer rang to cancel.', $cancelled->cancellation_reason);
        $this->assertTrue($cancelled->cancelled_at->equalTo($this->now));
    }

    // --- Who may ------------------------------------------------------------

    /**
     * `bookings.cancel` is not in §12 — the specification names no permission
     * for ending a hire at all. Settled with the operator 2026-08-08 at Counter
     * Clerk and above: the clerk is the one facing the customer, and cancelling
     * only starts the process. The refund that follows still needs a manager.
     */
    public function test_a_counter_clerk_may_cancel(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $cancelled = $this->cancellations->cancel(
            actor: User::factory()->withRole(StaffRole::CounterClerk)->create(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
        );

        $this->assertSame(BookingStatus::CancelledByCustomer, $cancelled->status);
    }

    public function test_a_staff_member_without_the_permission_may_not_cancel(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->cancellations->cancel(
            actor: User::factory()->create(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
        );
    }

    public function test_nothing_is_written_when_the_permission_is_refused(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $hold = VehicleHold::factory()->create([
            'vehicle_id' => $booking->vehicle_id,
            'booking_id' => $booking->getKey(),
        ]);

        try {
            $this->cancellations->cancel(
                actor: User::factory()->create(),
                booking: $booking,
                to: BookingStatus::CancelledByCustomer,
            );
        } catch (StaffPermissionDeniedException) {
            // Expected.
        }

        // The refusal happens before the lock, so the vehicle is still claimed
        // and the booking is untouched.
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertNull($hold->refresh()->released_at);
    }

    // --- The hold ----------------------------------------------------------

    /**
     * The open item this closes. A confirmed booking's hold runs to the end of
     * the hire; cancelling it must hand those days back to the fleet.
     */
    public function test_cancelling_releases_the_vehicle_hold(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $hold = VehicleHold::factory()->create([
            'vehicle_id' => $booking->vehicle_id,
            'booking_id' => $booking->getKey(),
            'expires_at' => $booking->dropoff_at,
        ]);

        $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
        );

        $hold->refresh();

        $this->assertNotNull($hold->released_at, 'The vehicle is still claimed by a cancelled booking.');
        $this->assertNull($hold->is_active);
    }

    /**
     * A failed reassignment can leave a booking holding two vehicles. Releasing
     * only the newest would leave the other claimed by a booking that no longer
     * exists — invisible, and permanent until the hire end date.
     */
    public function test_it_releases_every_unreleased_hold_not_merely_the_newest(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $first = VehicleHold::factory()->create([
            'vehicle_id' => $booking->vehicle_id,
            'booking_id' => $booking->getKey(),
        ]);

        $second = VehicleHold::factory()->create([
            'booking_id' => $booking->getKey(),
        ]);

        $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
        );

        $this->assertNotNull($first->refresh()->released_at);
        $this->assertNotNull($second->refresh()->released_at);
    }

    /**
     * A short-notice booking never had a hold. Spec §8.2 — nothing to release,
     * and no reason for that to be an error.
     */
    public function test_a_booking_with_no_hold_cancels_cleanly(): void
    {
        $booking = Booking::factory()->shortNotice()->create(['status' => BookingStatus::PendingPayment]);

        $cancelled = $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
        );

        $this->assertSame(BookingStatus::CancelledByCustomer, $cancelled->status);
    }

    // --- What §7.3 permits --------------------------------------------------

    public function test_it_refuses_a_transition_the_specification_does_not_allow(): void
    {
        // §7.3 has no route from pending_payment to cancelled_failed_kyc: KYC is
        // checked at the counter, which a booking awaiting payment has not
        // reached.
        $booking = Booking::factory()->create(['status' => BookingStatus::PendingPayment]);

        $this->expectException(InvalidBookingTransitionException::class);

        $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::CancelledFailedKyc,
        );
    }

    public function test_it_refuses_to_cancel_an_already_cancelled_booking(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::CancelledNonPayment]);

        $this->expectException(InvalidBookingTransitionException::class);

        $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
        );
    }

    /**
     * The guard that stops `cancel()` being used to move a booking forwards.
     *
     * `pending_payment` → `confirmed` is a legal transition, so the state
     * machine would allow it. Reaching it through this method would confirm a
     * booking and release its vehicle hold in one call.
     */
    public function test_it_refuses_a_target_that_is_not_an_ending(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::PendingPayment]);

        $this->expectException(InvalidBookingTransitionException::class);

        $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::Confirmed,
        );
    }

    public function test_a_completed_booking_cannot_be_reached_through_cancellation(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::VehicleReleased]);

        // Legal under §7.3, and still refused here: a completed hire is a car
        // that came back clean, not a cancellation, and routing it through this
        // service would stamp cancelled_at on it.
        $this->expectException(InvalidBookingTransitionException::class);

        $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::Completed,
        );
    }

    public function test_a_confirmed_booking_can_be_marked_a_no_show(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $cancelled = $this->cancellations->cancel(
            actor: $this->manager(),
            booking: $booking,
            to: BookingStatus::NoShow,
            reason: 'Did not arrive; phone unanswered.',
        );

        $this->assertSame(BookingStatus::NoShow, $cancelled->status);
        // Stamped for a no-show too — it is when the booking ended.
        $this->assertTrue($cancelled->cancelled_at->equalTo($this->now));
    }

    // --- The trail ---------------------------------------------------------

    public function test_it_audits_the_cancellation_against_the_acting_staff_member(): void
    {
        $booking = Booking::factory()->confirmed()->create();
        $manager = $this->manager();

        VehicleHold::factory()->create([
            'vehicle_id' => $booking->vehicle_id,
            'booking_id' => $booking->getKey(),
        ]);

        $this->cancellations->cancel(
            actor: $manager,
            booking: $booking,
            to: BookingStatus::CancelledByCustomer,
            reason: 'Change of plans.',
        );

        $entry = AuditLogEntry::query()
            ->where('booking_id', $booking->getKey())
            ->where('action', 'booking.cancelled')
            ->firstOrFail();

        $this->assertSame((int) $manager->getKey(), (int) $entry->actor_user_id);
        $this->assertSame('confirmed', $entry->status_before);
        $this->assertSame('cancelled_by_customer', $entry->status_after);
        $this->assertSame('Change of plans.', $entry->notes);
        // A person did this, not the clock.
        $this->assertFalse((bool) $entry->is_automatic);
        $this->assertCount(1, $entry->metadata['holds_released']);
    }

    private function manager(): User
    {
        return User::factory()->withRole(StaffRole::BranchManager)->create();
    }
}
