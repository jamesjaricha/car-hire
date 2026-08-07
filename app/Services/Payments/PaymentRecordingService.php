<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\AuditLoggerContract;
use App\Contracts\PaymentAdapterResolverContract;
use App\Contracts\PaymentRecordingServiceContract;
use App\Contracts\PaymentReferenceGeneratorContract;
use App\DataTransferObjects\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\StaffPermission;
use App\Exceptions\PaymentNotRecordableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Writes payment records. Confirming them is somebody else's job.
 *
 * WHY NOTHING HERE TOUCHES THE BOOKING'S PAYMENT POSITION
 *
 * Recording a receipt does not change how much has been paid — no money has
 * been verified yet. `bookings.payment_status`, `amount_paid` and `balance_due`
 * are recomputed from confirmed receipts only, by PaymentConfirmationService,
 * and they are recomputed together so they cannot drift apart. A convenient
 * update from here would be the second writer that makes that guarantee false.
 */
final class PaymentRecordingService implements PaymentRecordingServiceContract
{
    public function __construct(
        private readonly PaymentReferenceGeneratorContract $references,
        private readonly PaymentAdapterResolverContract $adapters,
        private readonly AuditLoggerContract $audit,
    ) {}

    public function raiseForBooking(Booking $booking, PaymentMethod $method): Payment
    {
        return DB::transaction(function () use ($booking, $method): Payment {
            // What the customer was told to pay, frozen at the moment they were
            // told. The booking's balance_due will move as receipts are
            // confirmed; this must not, or a shortfall becomes a moving target.
            $expected = $booking->pay_in_full
                ? Money::of($booking->grand_total)
                : Money::of($booking->booking_deposit_amount);

            $payment = Payment::query()->create([
                'booking_id' => $booking->getKey(),
                'operator_id' => $booking->operator_id,

                'payment_reference' => $this->references->forBooking($booking),
                'payment_method_code' => $method->code,

                'status' => PaymentStatus::AwaitingPayment,

                // "Deposit" here is the 50% part-payment of the hire, never the
                // refundable cash security deposit taken at the counter.
                'is_deposit' => ! $booking->pay_in_full,

                // Nothing has arrived. A receipt raised with its expected
                // amount already in `amount` would look, to every later query,
                // exactly like money that had been received.
                'amount' => Money::ZERO,
                'expected_amount' => $expected,
                'currency' => $booking->currency,

                'external_reference' => null,
                'notes' => null,
                'proof_path' => null,
                'proof_uploaded_at' => null,

                // The customer's own checkout raised this. No staff member was
                // involved, and claiming one would corrupt the audit trail.
                'recorded_by_user_id' => null,
                'matched_by_user_id' => null,
                'matched_at' => null,
            ]);

            $this->audit->record(new AuditEntry(
                action: AuditAction::PaymentRecorded,
                booking: $booking,
                entity: $payment,
                statusAfter: PaymentStatus::AwaitingPayment,
                amount: $expected,
                paymentReference: $payment->payment_reference,
                paymentMethod: $method->code,
                notes: 'Payment instructions issued at checkout.',
                metadata: ['is_deposit' => ! $booking->pay_in_full],
            ));

            return $payment;
        });
    }

    public function recordUnmatchedReceipt(
        User $actor,
        PaymentMethodCode $code,
        string $amount,
        ?string $externalReference = null,
        ?string $notes = null,
    ): Payment {
        $this->assertMayRecordManually($actor);

        // Refused rather than coerced. A card payment recorded by hand would be
        // money nobody can trace to a gateway settlement.
        if (! $this->adapters->has($code)) {
            throw PaymentNotRecordableException::methodCannotBeUsedManually($code->value);
        }

        $amount = Money::of($amount);

        if (! Money::isPositive($amount)) {
            throw PaymentNotRecordableException::amountNotPositive($amount);
        }

        return DB::transaction(function () use ($actor, $code, $amount, $externalReference, $notes): Payment {
            $payment = Payment::query()->create([
                // The whole point: nobody knows whose this is yet.
                'booking_id' => null,
                'operator_id' => null,

                'payment_reference' => $this->references->forUnmatchedReceipt(),
                'payment_method_code' => $code,

                // Money has arrived, but arriving is not the same as being
                // verified against a booking. It stays outstanding until
                // somebody attributes it and confirms it there.
                'status' => PaymentStatus::AwaitingPayment,

                'is_deposit' => false,

                'amount' => $amount,

                // No booking means nothing was ever asked for, so this receipt
                // cannot be short. It is unattributed, not deficient.
                'expected_amount' => null,

                'currency' => (string) config('carhire.currency', 'ZMW'),

                // The payer's own reference — the mobile money transaction id or
                // bank reference. This is what the receipt will eventually be
                // matched on, and it is why the column exists.
                'external_reference' => $externalReference,
                'notes' => $notes,

                'proof_path' => null,
                'proof_uploaded_at' => null,

                'recorded_by_user_id' => $actor->getKey(),
                'matched_by_user_id' => null,
                'matched_at' => null,
            ]);

            $this->audit->record(new AuditEntry(
                action: AuditAction::PaymentRecorded,
                actor: $actor,
                entity: $payment,
                statusAfter: PaymentStatus::AwaitingPayment,
                amount: $amount,
                paymentReference: $payment->payment_reference,
                paymentMethod: $code,
                notes: $notes ?? 'Unattributed receipt recorded.',
                metadata: array_filter([
                    'external_reference' => $externalReference,
                    'unmatched' => true,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $payment;
        });
    }

    /**
     * Spec §12 has no permission named "record a payment".
     *
     * The closest it offers is `payments.edit-manual-payment`, which covers
     * keying money in by hand, and that is what this is. Recorded in
     * OPEN-ITEMS.md as a judgement call for the operator to confirm rather than
     * left as an undocumented assumption — the alternative readings are that
     * any authenticated user may do it, which is worse, or that nobody may,
     * which leaves the unmatched queue with no way to be filled.
     */
    private function assertMayRecordManually(User $actor): void
    {
        if (! $actor->hasPermissionTo(StaffPermission::PaymentsEditManualPayment)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::PaymentsEditManualPayment);
        }
    }
}
