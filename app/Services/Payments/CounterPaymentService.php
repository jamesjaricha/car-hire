<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\CounterPaymentServiceContract;
use App\Contracts\PaymentConfirmationServiceContract;
use App\Contracts\PaymentRecordingServiceContract;
use App\DataTransferObjects\PaymentConfirmationResult;
use App\Enums\PaymentMethodCode;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\PaymentNotRecordableException;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Raise a receipt and confirm it, in one transaction, for money handed over in
 * person.
 *
 * WHY THIS ORCHESTRATOR EXISTS RATHER THAN THE UI DOING IT
 *
 * Taking a balance at the counter is two writes: a receipt, then a
 * confirmation. Left to the caller, every screen that takes money would
 * sequence them itself, and the first one to forget the transaction would leave
 * a receipt with no confirmation behind — money that looks unpaid and cannot be
 * confirmed again, because the reference is already spent.
 *
 * WHAT IT DOES NOT DO
 *
 * It adds no permission logic and skips no guard. Both services are called
 * normally, so `payments.record-manual`, the method's own §12 confirmation
 * permission, the §15.12 cash setting, the booking and payment row locks and
 * the unique key on `payment_confirmations` all apply exactly as they would
 * separately. This removes a step from the person at the desk, not from the
 * system.
 */
final class CounterPaymentService implements CounterPaymentServiceContract
{
    public function __construct(
        private readonly PaymentRecordingServiceContract $recording,
        private readonly PaymentConfirmationServiceContract $confirmations,
    ) {}

    public function take(
        User $actor,
        Booking $booking,
        PaymentMethodCode $code,
        string $amountReceived,
        ?string $notes = null,
    ): PaymentConfirmationResult {
        $amountReceived = Money::of($amountReceived);

        if (! Money::isPositive($amountReceived)) {
            throw PaymentNotRecordableException::amountNotPositive($amountReceived);
        }

        $method = $this->method($code);

        return DB::transaction(function () use ($actor, $booking, $method, $amountReceived, $notes): PaymentConfirmationResult {
            // Asked for what is actually still owed, read now rather than from
            // whatever the caller's copy of the booking says. A screen left
            // open while somebody else confirmed a transfer would otherwise
            // raise a receipt for a balance that no longer exists.
            $outstanding = Money::of(
                Booking::query()->whereKey($booking->getKey())->value('balance_due')
            );

            $payment = $this->recording->raiseForBooking(
                booking: $booking,
                method: $method,
                expectedAmount: $outstanding,
                recordedBy: $actor,
            );

            return $this->confirmations->confirm(
                actor: $actor,
                payment: $payment,
                amountReceived: $amountReceived,
                notes: $notes,
            );
        }, attempts: 3);
    }

    /**
     * The method must still be one the business accepts.
     *
     * Checked with `isOfferable()` rather than the full checkout gate: the
     * short-notice and lead-time rules of spec §8.2 are about whether a
     * customer can send money in time, which is meaningless for cash already on
     * the desk. A method the operator has switched off, or one killed by
     * configuration, is refused for staff exactly as it is for customers.
     */
    private function method(PaymentMethodCode $code): PaymentMethod
    {
        $method = PaymentMethod::query()->where('code', $code->value)->first();

        if (! $method instanceof PaymentMethod) {
            throw PaymentMethodNotAvailableException::unknown($code->value);
        }

        if (! $method->isOfferable()) {
            throw PaymentMethodNotAvailableException::notEnabled($code->value);
        }

        return $method;
    }
}
