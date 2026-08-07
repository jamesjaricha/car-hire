<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AuditLoggerContract;
use App\DataTransferObjects\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Models\AuditLogEntry;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private AuditLoggerContract $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = app(AuditLoggerContract::class);
    }

    /**
     * Spec §12 lists what every entry must record. This asserts the whole list
     * in one place, because a field that is merely usually populated is not an
     * audit trail.
     */
    public function test_it_records_everything_the_specification_requires(): void
    {
        $branch = Branch::factory()->create();
        $clerk = User::factory()->atBranch($branch)->create();
        $booking = Booking::factory()->create();
        $payment = Payment::factory()->forBooking($booking)->confirmed('1155.00')->create();

        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentConfirmed,
            actor: $clerk,
            booking: $booking,
            entity: $payment,
            statusBefore: PaymentStatus::AwaitingPayment,
            statusAfter: PaymentStatus::Confirmed,
            amount: '1155.00',
            paymentReference: $payment->payment_reference,
            paymentMethod: PaymentMethodCode::Cash,
            proofUploaded: false,
            notes: 'Counted at the desk.',
            metadata: ['till' => 'Lusaka 2'],
        ));

        $entry->refresh();

        $this->assertSame($booking->getKey(), $entry->booking_id);
        $this->assertSame($clerk->getKey(), $entry->actor_user_id);
        $this->assertSame('payment.confirmed', $entry->action);
        $this->assertSame('Payment', $entry->entity);
        $this->assertSame($payment->getKey(), $entry->entity_id);
        $this->assertSame('awaiting_payment', $entry->status_before);
        $this->assertSame('confirmed', $entry->status_after);
        $this->assertSame('1155.00', $entry->amount);
        $this->assertSame($payment->payment_reference, $entry->payment_reference);
        $this->assertSame('cash', $entry->payment_method_code);
        $this->assertFalse($entry->proof_uploaded);
        $this->assertSame($branch->getKey(), $entry->branch_id);
        $this->assertSame('Counted at the desk.', $entry->notes);
        $this->assertSame(['till' => 'Lusaka 2'], $entry->metadata);
        $this->assertFalse($entry->is_automatic);
        $this->assertNotNull($entry->created_at);
    }

    /**
     * Spec §12 wants to know whether a person or a job did this. That is not an
     * independent fact from "was there an actor", so it is derived rather than
     * passed — the two can then never contradict each other.
     */
    public function test_an_entry_with_no_actor_is_automatic(): void
    {
        $booking = Booking::factory()->create();

        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::BookingCancelledNonPayment,
            booking: $booking,
            statusBefore: BookingStatus::PendingPayment,
            statusAfter: BookingStatus::CancelledNonPayment,
        ));

        $this->assertTrue($entry->refresh()->is_automatic);
        $this->assertNull($entry->actor_user_id);
    }

    public function test_an_entry_with_an_actor_is_not_automatic(): void
    {
        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentConfirmed,
            actor: User::factory()->create(),
        ));

        $this->assertFalse($entry->refresh()->is_automatic);
    }

    /**
     * The branch that matters is the counter the person was standing at, not
     * the booking's pickup branch — those are routinely different, and only the
     * first answers "where was this money accepted".
     */
    public function test_the_branch_defaults_to_the_acting_staff_members_own(): void
    {
        $branch = Branch::factory()->create();
        $clerk = User::factory()->atBranch($branch)->create();

        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::SecurityDepositCollected,
            actor: $clerk,
        ));

        $this->assertSame($branch->getKey(), $entry->refresh()->branch_id);
    }

    public function test_an_explicit_branch_wins(): void
    {
        $usual = Branch::factory()->create();
        $today = Branch::factory()->create();
        $clerk = User::factory()->atBranch($usual)->create();

        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::SecurityDepositCollected,
            actor: $clerk,
            branch: $today,
        ));

        $this->assertSame($today->getKey(), $entry->refresh()->branch_id);
    }

    public function test_a_super_admin_with_no_branch_leaves_it_null(): void
    {
        $admin = User::factory()->create();

        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentMethodDisabled,
            actor: $admin,
        ));

        $this->assertNull($entry->refresh()->branch_id);
    }

    /**
     * An audit entry recording '300' against a payment holding '300.00' would
     * be the same money and a failed comparison. Normalising here means the
     * trail and the record it describes can be compared directly.
     */
    public function test_amounts_are_normalised_to_the_money_scale(): void
    {
        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentRecorded,
            amount: '300',
        ));

        $this->assertSame('300.00', $entry->refresh()->amount);
    }

    public function test_statuses_may_be_given_as_enums_or_strings(): void
    {
        $fromEnums = $this->audit->record(new AuditEntry(
            action: AuditAction::BookingConfirmed,
            statusBefore: BookingStatus::PendingPayment,
            statusAfter: BookingStatus::Confirmed,
        ));

        $fromStrings = $this->audit->record(new AuditEntry(
            action: AuditAction::BookingConfirmed,
            statusBefore: 'pending_payment',
            statusAfter: 'confirmed',
        ));

        $this->assertSame('pending_payment', $fromEnums->refresh()->status_before);
        $this->assertSame('confirmed', $fromEnums->status_after);

        $this->assertSame($fromEnums->status_before, $fromStrings->refresh()->status_before);
        $this->assertSame($fromEnums->status_after, $fromStrings->status_after);
    }

    /**
     * "Nothing extra was recorded" should read the same in every row, rather
     * than being null in some and an empty object in others.
     */
    public function test_empty_metadata_is_stored_as_null(): void
    {
        $entry = $this->audit->record(new AuditEntry(action: AuditAction::KycVerified));

        $this->assertNull($entry->refresh()->metadata);
    }

    public function test_an_entry_needs_neither_a_booking_nor_an_entity(): void
    {
        // Enabling a payment method is audited by §12 and belongs to no booking.
        $entry = $this->audit->record(new AuditEntry(
            action: AuditAction::PaymentMethodEnabled,
            actor: User::factory()->create(),
            notes: 'Airtel Money switched on.',
        ));

        $entry->refresh();

        $this->assertNull($entry->booking_id);
        $this->assertNull($entry->entity);
        $this->assertNull($entry->entity_id);
        $this->assertSame('payment-method.enabled', $entry->action);
    }

    /**
     * The trail is append-only at the database level, proven in
     * AuditLogImmutabilityTest. This only checks that what the logger writes is
     * a real row in that table rather than something exempt from it.
     */
    public function test_what_it_writes_lands_in_the_audit_log(): void
    {
        $this->audit->record(new AuditEntry(action: AuditAction::PaymentExpired));

        $this->assertDatabaseCount('audit_log', 1);
        $this->assertInstanceOf(AuditLogEntry::class, AuditLogEntry::query()->sole());
    }
}
