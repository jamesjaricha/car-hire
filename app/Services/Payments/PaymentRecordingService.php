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
use Carbon\CarbonImmutable;
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

    public function matchToBooking(
        User $actor,
        Payment $payment,
        Booking $booking,
        ?string $notes = null,
    ): Payment {
        $this->assertMayRecordManually($actor);

        return DB::transaction(function () use ($actor, $payment, $booking, $notes): Payment {
            // Booking then payment, the same order PaymentConfirmationService
            // and PaymentReferenceGenerator use. One lock order across every
            // transaction that holds both is what keeps them queuing instead of
            // deadlocking.
            $lockedBooking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

            if (! $lockedBooking instanceof Booking) {
                throw PaymentNotRecordableException::amountNotPositive('0.00');
            }

            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Payment) {
                throw PaymentNotRecordableException::alreadyAttributed($payment->payment_reference);
            }

            // Re-checked under the lock. Two staff working the same queue is
            // exactly the situation this exists to handle.
            if ($locked->booking_id !== null) {
                throw PaymentNotRecordableException::alreadyAttributed($locked->payment_reference);
            }

            // Confirmed money is already counted against some balance. Moving
            // it would change two bookings' totals at once, and neither would
            // be recomputed.
            if ($locked->status === PaymentStatus::Confirmed) {
                throw PaymentNotRecordableException::confirmedPaymentCannotBeMoved($locked->payment_reference);
            }

            $locked->forceFill([
                'booking_id' => $lockedBooking->getKey(),
                'operator_id' => $lockedBooking->operator_id,
                'matched_by_user_id' => $actor->getKey(),
                'matched_at' => CarbonImmutable::now(),

                // `expected_amount` stays null. Nothing was ever asked for on
                // this receipt, and back-filling it with the booking's balance
                // would invent a shortfall out of an amount the customer was
                // never quoted.
            ])->save();

            $this->audit->record(new AuditEntry(
                action: AuditAction::PaymentMatched,
                actor: $actor,
                booking: $lockedBooking,
                entity: $locked,
                amount: $locked->amount,
                paymentReference: $locked->payment_reference,
                paymentMethod: $locked->payment_method_code,
                notes: $notes ?? 'Unattributed receipt matched to a booking.',
                metadata: array_filter([
                    'external_reference' => $locked->external_reference,
                    'booking_reference' => $lockedBooking->reference,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $locked;
        }, attempts: 3);
    }

    /**
     * Spec §12 has no permission named "record a payment", so one was added.
     *
     * Guarding this with `payments.edit-manual-payment` — the nearest thing on
     * the §12 list — forced a choice between two wrong answers: let counter
     * clerks alter payments already recorded, or stop the people standing at
     * the till from writing money down as it arrives. The operator chose
     * neither, and `payments.record-manual` exists so that recording what
     * arrived and changing what was already recorded are separate powers.
     *
     * Counter clerks hold it. They are the ones who see the money.
     */
    private function assertMayRecordManually(User $actor): void
    {
        if (! $actor->hasPermissionTo(StaffPermission::PaymentsRecordManual)) {
            throw StaffPermissionDeniedException::missing(StaffPermission::PaymentsRecordManual);
        }
    }
}
