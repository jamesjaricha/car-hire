<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentMethodCode;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Writes payment records. Never confirms them.
 *
 * The split matters. Recording says money is expected, or that money was seen.
 * Confirming says a person checked that it actually arrived, and that is what
 * moves a booking forward — spec §7.3 is explicit that only a staff
 * confirmation does. Keeping them in separate services means no call site can
 * do the second by accident while meaning the first.
 */
interface PaymentRecordingServiceContract
{
    /**
     * Raise the receipt a booking is waiting on. Spec §14.3.
     *
     * "Any offline method creates a booking in pending_payment, a payment
     * record in awaiting_payment, and a unique payment reference." Called by
     * BookingCreationService inside its transaction, so a failure anywhere
     * leaves neither a booking nor a payment behind.
     *
     * The expected amount comes from the booking: the full total or the
     * deposit, according to what the customer chose at checkout.
     */
    public function raiseForBooking(Booking $booking, PaymentMethod $method): Payment;

    /**
     * Write down money that arrived without anyone knowing whose it is.
     *
     * The guideline §5 promises a queue of these: mobile money statements lag
     * and till payments often do not carry our reference. The money is real and
     * has to be in the system the moment it is seen — otherwise it lives on a
     * note beside the till, which is where money goes missing.
     *
     * It is recorded, not confirmed. Nothing is settled until someone attributes
     * it to a booking and confirms it there.
     */
    public function recordUnmatchedReceipt(
        User $actor,
        PaymentMethodCode $code,
        string $amount,
        ?string $externalReference = null,
        ?string $notes = null,
    ): Payment;
}
