<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The booking screens.
 *
 * Two things are being checked, and the second matters more than the first.
 * That the queue shows the right bookings to the right people — and that the
 * resource is genuinely read-only, rather than read-only by convention. If
 * somebody later generates a form against Booking, these should fail.
 */
final class BookingResourceTest extends TestCase
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
    }

    // --- Read-only, enforced ----------------------------------------------

    /**
     * ARCHITECTURE §11. A booking's status belongs to the state machine, its
     * money to the confirmation service, its deadline to the extension service.
     * A form here would write past all three and nothing would fail — the row
     * would simply be wrong.
     */
    public function test_the_resource_offers_no_way_to_create_or_edit_a_booking(): void
    {
        $pages = array_keys(BookingResource::getPages());

        $this->assertSame(['index', 'view'], $pages);
    }

    public function test_the_policy_refuses_creation_and_editing_outright(): void
    {
        // Not merely hidden. Filament reads the policy to decide what to
        // render, so this is what makes read-only survive somebody generating
        // a form in a hurry.
        $admin = User::factory()->withRole(StaffRole::SuperAdmin)->create();
        $booking = Booking::factory()->create();

        $this->assertFalse($admin->can('create', Booking::class));
        $this->assertFalse($admin->can('update', $booking));
        $this->assertFalse($admin->can('delete', $booking));

        // And the thing it must still allow.
        $this->assertTrue($admin->can('viewAny', Booking::class));
    }

    public function test_the_create_and_edit_routes_do_not_exist(): void
    {
        $booking = Booking::factory()->create();

        $this->actingAs(User::factory()->withRole(StaffRole::SuperAdmin)->create());

        $this->get('/admin/bookings/create')->assertNotFound();
        $this->get("/admin/bookings/{$booking->getKey()}/edit")->assertNotFound();
    }

    // --- Who sees the list -------------------------------------------------

    public function test_a_counter_clerk_may_see_bookings(): void
    {
        // They hold payments.view and serve customers at the desk.
        $this->actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->get(BookingResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_a_user_with_no_role_may_not(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(BookingResource::getUrl('index'))
            ->assertForbidden();
    }

    // --- The queue ---------------------------------------------------------

    /**
     * The reason this slice exists. These bookings hold customers' money and
     * their deadline has passed; the expiry sweep deliberately refuses to touch
     * them, so nothing else will ever surface them to a person.
     */
    public function test_the_stalled_tab_shows_only_bookings_holding_money_past_their_deadline(): void
    {
        $stalled = $this->stalledBooking();
        $unpaidPastDeadline = $this->booking(['payment_deadline_at' => $this->now->subHour()]);
        $stillInTime = $this->booking(['payment_deadline_at' => $this->now->addDay()]);

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class, ['activeTab' => 'stalled'])
            ->assertCanSeeTableRecords([$stalled])
            ->assertCanNotSeeTableRecords([$unpaidPastDeadline, $stillInTime]);
    }

    public function test_a_counter_clerk_is_not_offered_the_stalled_tab(): void
    {
        // Every action on it is Branch Manager and above. A list of problems a
        // clerk cannot touch invites chasing customers off-system, which is how
        // a queue stops reflecting reality.
        $tabs = Livewire::actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->test(ListBookings::class)
            ->instance()
            ->getTabs();

        $this->assertArrayNotHasKey('stalled', $tabs);
        $this->assertArrayHasKey('all', $tabs);
    }

    public function test_a_branch_manager_is_offered_the_stalled_tab(): void
    {
        $tabs = Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class)
            ->instance()
            ->getTabs();

        $this->assertArrayHasKey('stalled', $tabs);
    }

    // --- Actions -----------------------------------------------------------

    public function test_a_manager_can_take_the_balance_from_the_list(): void
    {
        $booking = $this->stalledBooking();
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        Livewire::actingAs($manager)
            ->test(ListBookings::class)
            // A row action is targeted with TestAction::table($record) in
            // Filament 5; callAction() takes no `record` argument.
            ->callAction(TestAction::make('takeBalance')->table($booking), data: [
                'method' => PaymentMethodCode::Cash->value,
                'amount' => '1155.00',
                'notes' => 'Paid at the desk.',
            ])
            ->assertHasNoActionErrors();

        $booking->refresh();

        $this->assertSame('0.00', $booking->balance_due);
        $this->assertSame(BookingPaymentStatus::PaidInFull, $booking->payment_status);
    }

    public function test_a_manager_can_extend_a_deadline_from_the_list(): void
    {
        $booking = $this->booking(['payment_deadline_at' => $this->now->addHours(6)]);
        $manager = User::factory()->withRole(StaffRole::BranchManager)->create();

        Livewire::actingAs($manager)
            ->test(ListBookings::class)
            ->callAction(TestAction::make('extendDeadline')->table($booking), data: [
                'deadline' => $this->now->addHours(48)->toDateTimeString(),
                'reason' => 'Transfer left this morning.',
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue($booking->refresh()->payment_deadline_at->equalTo($this->now->addHours(48)));
    }

    /**
     * The action holds no permission logic of its own — the service refuses it.
     * Hiding the button as well means staff are not invited to click something
     * that cannot work.
     */
    public function test_a_counter_clerk_is_not_offered_the_extend_action(): void
    {
        $booking = $this->booking();

        Livewire::actingAs(User::factory()->withRole(StaffRole::CounterClerk)->create())
            ->test(ListBookings::class)
            ->assertTableActionHidden('extendDeadline', $booking);
    }

    public function test_the_take_payment_action_is_hidden_when_nothing_is_owed(): void
    {
        $settled = $this->booking([
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::PaidInFull,
            'amount_paid' => '2310.00',
            'balance_due' => '0.00',
        ]);

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class)
            ->assertTableActionHidden('takeBalance', $settled);
    }

    /**
     * The guard the Phase 3 audit added. A cancelled booking cannot take money,
     * and the button must not be there to try.
     */
    public function test_the_take_payment_action_is_hidden_on_a_cancelled_booking(): void
    {
        $cancelled = $this->booking([
            'status' => BookingStatus::CancelledNonPayment,
            'balance_due' => '2310.00',
        ]);

        Livewire::actingAs(User::factory()->withRole(StaffRole::BranchManager)->create())
            ->test(ListBookings::class)
            ->assertTableActionHidden('takeBalance', $cancelled);
    }

    // --- Fixtures ----------------------------------------------------------

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
            'pickup_at' => $this->now->addDays(10),
            'dropoff_at' => $this->now->addDays(13),
            'payment_deadline_at' => $this->now->addHours(24),
        ], $attributes));
    }

    /**
     * Part-paid, and past its deadline: the queue's entire reason for being.
     */
    private function stalledBooking(): Booking
    {
        $booking = $this->booking([
            'payment_status' => BookingPaymentStatus::PartiallyPaid,
            'amount_paid' => '1155.00',
            'balance_due' => '1155.00',
            'payment_deadline_at' => $this->now->subHours(2),
        ]);

        Payment::factory()->forBooking($booking)->deposit()->create([
            'payment_reference' => $booking->reference.'-1',
            'payment_method_code' => PaymentMethodCode::BankTransfer,
            'status' => PaymentStatus::Confirmed,
            'amount' => '1155.00',
        ]);

        return $booking;
    }
}
