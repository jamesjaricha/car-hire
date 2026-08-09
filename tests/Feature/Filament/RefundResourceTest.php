<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Contracts\SettingsRepositoryContract;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\SettingKey;
use App\Enums\StaffRole;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\RefundResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundDisbursement;
use App\Models\User;
use App\Models\VehicleHold;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The refund screens.
 *
 * Three things are being checked, in descending order of how much they matter:
 * that the resource is genuinely read-only rather than read-only by convention;
 * that §9.3's two-person rule is visible in the UI as well as enforced in the
 * service; and that the one button which cancels a booking and raises a refund
 * does both or neither.
 */
final class RefundResourceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-06T09:00:00Z');
        $this->travelTo($this->now);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        app(SettingsRepositoryContract::class)->set(SettingKey::AdminFeeAmount, '150.00');
    }

    // --- Read-only, enforced ------------------------------------------------

    /**
     * ARCHITECTURE §11. The amount is computed from §9 and frozen, the approver
     * is subject to a two-person rule, and the status is derived from a
     * disbursement row. All three are exactly what a generated form would offer.
     */
    public function test_the_resource_offers_no_way_to_create_or_edit_a_refund(): void
    {
        $this->assertSame(['index', 'view'], array_keys(RefundResource::getPages()));
    }

    public function test_the_policy_refuses_creation_and_editing_outright(): void
    {
        $admin = User::factory()->withRole(StaffRole::SuperAdmin)->create();
        $refund = $this->requestedRefund();

        $this->assertFalse($admin->can('create', Refund::class));
        $this->assertFalse($admin->can('update', $refund));
        $this->assertFalse($admin->can('delete', $refund));

        $this->assertTrue($admin->can('viewAny', Refund::class));
    }

    public function test_the_create_and_edit_routes_do_not_exist(): void
    {
        $refund = $this->requestedRefund();

        $this->actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create());

        $this->get('/admin/refunds/create')->assertNotFound();
        $this->get("/admin/refunds/{$refund->getKey()}/edit")->assertNotFound();
    }

    // --- Who sees it --------------------------------------------------------

    public function test_a_counter_clerk_may_see_refunds(): void
    {
        // They raise them at the counter and field the customer chasing them.
        $this->actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->get(RefundResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_a_user_with_no_role_may_not(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(RefundResource::getUrl('index'))
            ->assertForbidden();
    }

    // --- The queues ---------------------------------------------------------

    public function test_the_awaiting_payout_tab_shows_only_approved_refunds(): void
    {
        $awaitingApproval = $this->requestedRefund();
        $awaitingPayout = $this->approvedRefund();

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class, ['activeTab' => 'awaiting_payout'])
            ->assertCanSeeTableRecords([$awaitingPayout])
            ->assertCanNotSeeTableRecords([$awaitingApproval]);
    }

    // --- §9.3, in the interface --------------------------------------------

    public function test_a_manager_can_approve_a_refund_somebody_else_raised(): void
    {
        $refund = $this->requestedRefund();

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class)
            ->callAction(TestAction::make('approveRefund')->table($refund), data: [
                'notes' => 'Spoke to the customer.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(RefundStatus::Approved, $refund->refresh()->status);
    }

    /**
     * The two-person rule, made visible.
     *
     * The service refuses this anyway. Hiding the button means the person who
     * raised it is not invited to press something that would then accuse them of
     * breaking a fraud control.
     */
    public function test_the_person_who_raised_a_refund_is_not_offered_the_approve_button(): void
    {
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();
        $refund = $this->requestedRefund(requestedBy: $manager);

        Livewire::actingAs($manager)
            ->test(ListRefunds::class)
            ->assertTableActionHidden('approveRefund', $refund);
    }

    public function test_a_counter_clerk_is_not_offered_the_approve_button(): void
    {
        $refund = $this->requestedRefund();

        Livewire::actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->test(ListRefunds::class)
            ->assertTableActionHidden('approveRefund', $refund);
    }

    public function test_a_manager_can_reject_with_a_reason(): void
    {
        $refund = $this->requestedRefund();

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class)
            ->callAction(TestAction::make('rejectRefund')->table($refund), data: [
                'reason' => 'Outside the terms the customer accepted.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(RefundStatus::Rejected, $refund->refresh()->status);
    }

    // --- The payout ---------------------------------------------------------

    public function test_a_manager_can_record_a_payout_with_its_reference(): void
    {
        $refund = $this->approvedRefund();

        // The tab has to be named. `awaiting_approval` is declared first and is
        // therefore the default, and an approved refund is not in its query —
        // Filament cannot resolve a row action against a record the table is not
        // showing. This is also where staff would actually be standing.
        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class, ['activeTab' => 'awaiting_payout'])
            ->callAction(TestAction::make('disburseRefund')->table($refund), data: [
                'reference' => 'MM-4471',
                'notes' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(RefundStatus::Disbursed, $refund->refresh()->status);
        $this->assertDatabaseHas('refund_disbursements', [
            'refund_id' => $refund->getKey(),
            'disbursement_reference' => 'MM-4471',
        ]);
    }

    /**
     * Spec §9.3 requires proof. A blank reference means the money has not left.
     */
    public function test_the_payout_form_requires_a_reference(): void
    {
        $refund = $this->approvedRefund();

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class, ['activeTab' => 'awaiting_payout'])
            ->callAction(TestAction::make('disburseRefund')->table($refund), data: [
                'reference' => '',
            ])
            ->assertHasActionErrors(['reference' => ['required']]);

        $this->assertDatabaseCount('refund_disbursements', 0);
    }

    public function test_an_unapproved_refund_offers_no_payout_button(): void
    {
        $refund = $this->requestedRefund();

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class)
            ->assertTableActionHidden('disburseRefund', $refund);
    }

    public function test_an_already_paid_refund_offers_no_payout_button(): void
    {
        $refund = $this->approvedRefund();

        RefundDisbursement::factory()
            ->forRefund($refund)
            ->disbursedBy(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->create();

        $refund->forceFill(['status' => RefundStatus::Disbursed])->save();

        // A paid refund is in neither queue, so this one has to look at All.
        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListRefunds::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('disburseRefund', $refund);
    }

    // --- Cancel and refund, from the booking screens ------------------------

    public function test_cancelling_a_booking_raises_a_refund_and_releases_the_vehicle(): void
    {
        $booking = $this->confirmedBookingHolding('2310.00');

        $hold = VehicleHold::factory()->create([
            'vehicle_id' => $booking->vehicle_id,
            'booking_id' => $booking->getKey(),
            'expires_at' => $booking->dropoff_at,
        ]);

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class)
            ->callAction(TestAction::make('cancelAndRefund')->table($booking), data: [
                'reason' => RefundReason::CustomerCancellation->value,
                'method' => PaymentMethodCode::BankTransfer->value,
                'notes' => 'Customer rang to cancel.',
            ])
            ->assertHasNoActionErrors();

        $booking->refresh();

        $this->assertSame(BookingStatus::CancelledByCustomer, $booking->status);
        $this->assertNotNull($hold->refresh()->released_at, 'The vehicle is still claimed.');

        $refund = Refund::query()->where('booking_id', $booking->getKey())->firstOrFail();

        $this->assertSame('2160.00', $refund->amount);
        $this->assertSame(RefundStatus::Requested, $refund->status);
    }

    /**
     * A late cancellation can forfeit exactly what was paid. The booking is
     * still cancelled; no refund row is created, because one for zero could
     * never be disbursed.
     */
    public function test_a_cancellation_with_nothing_refundable_still_cancels(): void
    {
        // Paid the deposit, cancelling two hours before pickup.
        $booking = $this->confirmedBookingHolding('1155.00', pickupAt: $this->now->addHours(2));

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class)
            ->callAction(TestAction::make('cancelAndRefund')->table($booking), data: [
                'reason' => RefundReason::CustomerCancellation->value,
                'notes' => 'Cancelled on the morning of pickup.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(BookingStatus::CancelledByCustomer, $booking->refresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_the_cancel_action_is_hidden_on_a_booking_that_has_already_ended(): void
    {
        $cancelled = $this->confirmedBookingHolding('2310.00');
        $cancelled->forceFill(['status' => BookingStatus::CancelledNonPayment])->save();

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class)
            ->assertTableActionHidden('cancelAndRefund', $cancelled);
    }

    // --- Fixtures -----------------------------------------------------------

    private function confirmedBookingHolding(string $amountPaid, ?CarbonImmutable $pickupAt = null): Booking
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::PartiallyPaid,
            'grand_total' => '2310.00',
            'booking_deposit_amount' => '1155.00',
            'amount_paid' => $amountPaid,
            'balance_due' => '0.00',
            'pickup_at' => $pickupAt ?? $this->now->addDays(5),
            'dropoff_at' => ($pickupAt ?? $this->now->addDays(5))->addDays(3),
            'confirmed_at' => $this->now,
        ]);

        Payment::factory()->forBooking($booking)->confirmed($amountPaid)->create([
            'payment_reference' => $booking->reference.'-1',
            'expected_amount' => $amountPaid,
        ]);

        return $booking;
    }

    private function requestedRefund(?User $requestedBy = null): Refund
    {
        $booking = $this->confirmedBookingHolding('2310.00');

        return Refund::factory()
            ->forBooking($booking)
            ->requestedBy($requestedBy ?? User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->create();
    }

    private function approvedRefund(): Refund
    {
        $refund = $this->requestedRefund();

        $refund->forceFill([
            'status' => RefundStatus::Approved,
            'approved_by_user_id' => User::factory()->withRole(StaffRole::BranchManager)->create()->getKey(),
            'approved_at' => $this->now,
        ])->save();

        return $refund;
    }
}
