<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * A payment deadline cannot be moved as asked.
 *
 * Spec §8.2 lets staff "manually extend any deadline or approve an exception",
 * so this is deliberately permissive about *how far* — a manager who has spoken
 * to the customer knows more than the automatic rule does. What it refuses is
 * incoherence: a deadline after the pickup it is supposed to precede, a booking
 * with no deadline to extend, or one that is no longer waiting to be paid.
 */
final class DeadlineNotExtendableException extends DomainException
{
    public static function bookingIsNotAwaitingPayment(string $reference, BookingStatus $status): self
    {
        return new self(sprintf(
            'Booking [%s] is %s, so it has no payment deadline to extend.',
            $reference,
            lcfirst($status->label()),
        ));
    }

    /**
     * Spec §8.2 places no hold and sets no deadline for a short-notice booking:
     * the customer pays at the counter and the vehicle is first-come.
     */
    public static function bookingHasNoDeadline(string $reference): self
    {
        return new self(
            "Booking [{$reference}] was taken at short notice and never had a payment deadline. "
            .'It is settled at the counter, and the vehicle was never held.'
        );
    }

    public static function notInTheFuture(CarbonImmutable $deadline, CarbonImmutable $now): self
    {
        return new self(sprintf(
            'A payment deadline of %s is not in the future — it is now %s.',
            $deadline->toDateTimeString(),
            $now->toDateTimeString(),
        ));
    }

    /**
     * Refused because it is meaningless rather than because it is generous. A
     * deadline after pickup would let a customer collect a car they have not
     * paid for and still be inside their window.
     */
    public static function afterPickup(CarbonImmutable $deadline, CarbonImmutable $pickupAt): self
    {
        return new self(sprintf(
            'A payment deadline of %s falls after the %s pickup. '
            .'The customer would be collecting the vehicle before they were due to pay for it.',
            $deadline->toDateTimeString(),
            $pickupAt->toDateTimeString(),
        ));
    }

    public static function notAnExtension(CarbonImmutable $deadline, CarbonImmutable $current): self
    {
        return new self(sprintf(
            'The current deadline is %s; %s is earlier. Bringing a deadline forward shortens a promise '
            .'already made to the customer, which is a cancellation decision rather than an extension.',
            $current->toDateTimeString(),
            $deadline->toDateTimeString(),
        ));
    }
}
