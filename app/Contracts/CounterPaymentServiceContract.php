<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\PaymentConfirmationResult;
use App\Enums\PaymentMethodCode;
use App\Models\Booking;
use App\Models\User;

/**
 * Money handed over in person, recorded and confirmed in one step.
 *
 * The counter case is genuinely different from the checkout case. Online, a
 * receipt is raised and then confirmed hours later when somebody checks a
 * statement — two acts, separated by time, by different people, and the gap
 * between them is where `proof_submitted` lives. At a counter the staff member
 * IS the verification: they are holding the cash. Making them raise a receipt
 * and then separately confirm the money they just counted would be theatre, and
 * the kind that gets skipped.
 *
 * So this is one call — but it is not a shortcut past the guarantees. It calls
 * the same two services in the same order, so every permission check, every
 * lock and the unique key on `payment_confirmations` all still apply. It only
 * removes a step from the person, not from the system.
 *
 * Spec §5: "Balance is recorded as due and must be settled before handover."
 * This is how it gets settled.
 */
interface CounterPaymentServiceContract
{
    /**
     * Take money at the counter against a booking.
     *
     * The receipt is raised for whatever is currently outstanding and confirmed
     * for what actually changed hands — which is not necessarily the same
     * figure, and the difference is reported as a shortfall exactly as it would
     * be for a bank transfer.
     *
     * Needs `payments.record-manual` to write the receipt and the method's own
     * §12 permission to confirm it. Both are enforced by the services beneath,
     * so a counter clerk can take cash but cannot sign off a transfer that
     * happened to arrive while they were at the desk.
     */
    public function take(
        User $actor,
        Booking $booking,
        PaymentMethodCode $code,
        string $amountReceived,
        ?string $notes = null,
    ): PaymentConfirmationResult;
}
