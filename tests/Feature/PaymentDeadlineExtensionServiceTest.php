<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BookingExpiryServiceContract;
use App\Contracts\PaymentDeadlineExtensionServiceContract;
use App\Contracts\VehicleHoldServiceContract;
use App\DataTransferObjects\DateRange;
use App\Enums\BookingStatus;
use App\Enums\StaffRole;
use App\Exceptions\DeadlineNotExtendableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentDeadlineExtensionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentDeadlineExtensionServiceContract $extensions;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->extensions = app(PaymentDeadlineExtensionServiceContract::class);
    }

    public function test_it_moves_the_deadline(): void
    {
        $booking = $this->booking();
        $newDeadline = $this->now->addHours(72);

        $this->extensions->extend($this->manager(), $booking, $newDeadline, 'Customer says the transfer left this morning.');

        $this->assertTrue($booking->refresh()->payment_deadline_at->equalTo($newDeadline));
    }

    /**
     * The point of the whole service. A deadline and the hold behind it are one
     * fact in two places: extending the first alone hands the customer another
     * day and releases their car to the next person who searches for it.
     */
    public function test_it_moves_the_hold_with_the_deadline(): void
    {
        [$booking, $hold] = $this->bookingWithHold();
        $newDeadline = $this->now->addHours(72);

        $this->extensions->extend($this->manager(), $booking, $newDeadline);

        $this->assertTrue($hold->refresh()->expires_at->equalTo($newDeadline));
    }

    public function test_the_vehicle_is_still_claimed_past_the_original_deadline(): void
    {
        [$booking, $hold] = $this->bookingWithHold();
        $original = $booking->payment_deadline_at;

        $this->extensions->extend($this->manager(), $booking, $this->now->addHours(72));

        $this->travelTo($original->addMinute());

        $this->assertSame(
            1,
            VehicleHold::query()->whereKey($hold->getKey())->stillClaiming()->count(),
            'The extra time was given to the customer but not to the vehicle.',
        );
    }

    /**
     * A reminder left pointing inside the old window has already passed and
     * will never fire, so the customer gets extra time and no nudge at all.
     */
    public function test_the_reminder_is_recalculated(): void
    {
        $booking = $this->booking();
        $originalReminder = $booking->payment_reminder_at;

        $this->extensions->extend($this->manager(), $booking, $this->now->addHours(72));

        $booking->refresh();

        $this->assertNotNull($booking->payment_reminder_at);
        $this->assertTrue($booking->payment_reminder_at->greaterThan($originalReminder));
        $this->assertTrue($booking->payment_reminder_at->lessThan($booking->payment_deadline_at));
    }

    public function test_an_extended_booking_survives_the_expiry_sweep(): void
    {
        // The reason a manager extends anything. Without this the sweep would
        // cancel the booking at the original deadline regardless.
        $booking = $this->booking();
        $original = $booking->payment_deadline_at;

        $this->extensions->extend($this->manager(), $booking, $this->now->addHours(72));

        $this->travelTo($original->addHour());

        app(BookingExpiryServiceContract::class)->sweep();

        $this->assertSame(BookingStatus::PendingPayment, $booking->refresh()->status);
    }

    public function test_it_is_audited(): void
    {
        $booking = $this->booking();
        $manager = $this->manager();

        $this->extensions->extend($manager, $booking, $this->now->addHours(72), 'Spoke to the customer.');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'payment.deadline-extended',
            'booking_id' => $booking->getKey(),
            'actor_user_id' => $manager->getKey(),
            'is_automatic' => false,
            'notes' => 'Spoke to the customer.',
        ]);
    }

    // --- Refusals ----------------------------------------------------------

    public function test_a_counter_clerk_may_not_extend_a_deadline(): void
    {
        $booking = $this->booking();

        $this->expectException(StaffPermissionDeniedException::class);

        $this->extensions->extend($this->clerk(), $booking, $this->now->addHours(72));
    }

    public function test_a_refusal_changes_nothing(): void
    {
        $booking = $this->booking();
        $original = $booking->payment_deadline_at;

        try {
            $this->extensions->extend($this->clerk(), $booking, $this->now->addHours(72));
            $this->fail('The extension should have been refused.');
        } catch (StaffPermissionDeniedException) {
            // Expected.
        }

        $this->assertTrue($booking->refresh()->payment_deadline_at->equalTo($original));
        $this->assertDatabaseCount('audit_log', 0);
    }

    /**
     * Refused as meaningless rather than as generous: the customer would be
     * collecting the vehicle before they were due to pay for it.
     */
    public function test_a_deadline_after_pickup_is_refused(): void
    {
        $booking = $this->booking();

        $this->expectException(DeadlineNotExtendableException::class);

        $this->extensions->extend($this->manager(), $booking, $booking->pickup_at->addHour());
    }

    public function test_a_deadline_in_the_past_is_refused(): void
    {
        $booking = $this->booking();

        $this->expectException(DeadlineNotExtendableException::class);

        $this->extensions->extend($this->manager(), $booking, $this->now->subHour());
    }

    public function test_bringing_a_deadline_forward_is_refused(): void
    {
        // Shortening a promise already made to a customer is a cancellation
        // decision, not an extension, and it should not wear this name.
        $booking = $this->booking();

        $this->expectException(DeadlineNotExtendableException::class);

        $this->extensions->extend($this->manager(), $booking, $this->now->addHours(2));
    }

    public function test_a_confirmed_booking_has_no_deadline_to_extend(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->expectException(DeadlineNotExtendableException::class);

        $this->extensions->extend($this->manager(), $booking, $this->now->addHours(72));
    }

    public function test_a_short_notice_booking_has_no_deadline_to_extend(): void
    {
        // Spec §8.2: no deadline and no hold. It is settled at the counter.
        $booking = $this->booking([
            'is_short_notice' => true,
            'payment_deadline_at' => null,
        ]);

        $this->expectException(DeadlineNotExtendableException::class);

        $this->extensions->extend($this->manager(), $booking, $this->now->addHours(2));
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'status' => BookingStatus::PendingPayment,
            'pickup_at' => $this->now->addDays(10),
            'dropoff_at' => $this->now->addDays(13),
            'payment_deadline_at' => $this->now->addHours(24),
            'payment_reminder_at' => $this->now->addHours(18),
            'is_short_notice' => false,
        ], $attributes));
    }

    /**
     * @return array{0: Booking, 1: VehicleHold}
     */
    private function bookingWithHold(): array
    {
        $booking = $this->booking();

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

        return [$booking, $hold];
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
