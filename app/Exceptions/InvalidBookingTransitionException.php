<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;
use App\Enums\TransitionActor;
use DomainException;

/**
 * Raised when a booking is asked to move somewhere it cannot go.
 *
 * The guideline is explicit that undefined transitions must be rejected with an
 * exception rather than a silent no-op. A booking that quietly refuses to change
 * state looks, from the outside, exactly like one that changed — which is how a
 * vehicle gets handed over on a booking that was never paid.
 */
final class InvalidBookingTransitionException extends DomainException
{
    public static function notAllowed(BookingStatus $from, BookingStatus $to): self
    {
        return new self(sprintf(
            'A booking cannot move from %s to %s. There is no such transition in the specification.',
            $from->value,
            $to->value,
        ));
    }

    public static function wrongActor(BookingStatus $from, BookingStatus $to, TransitionActor $actor): self
    {
        return new self(sprintf(
            'A %s may not move a booking from %s to %s.',
            $actor->value,
            $from->value,
            $to->value,
        ));
    }

    public static function balanceOutstanding(string $balanceDue): self
    {
        return new self(sprintf(
            'A vehicle cannot be released while %s remains outstanding. '
            .'The balance must be settled before handover.',
            $balanceDue,
        ));
    }

    public static function securityDepositNotTaken(): self
    {
        return new self(
            'A vehicle cannot be released before the refundable security deposit has been recorded.'
        );
    }

    /**
     * `BookingCancellationService` was pointed at a state that is not an ending.
     *
     * The state machine would refuse most of these anyway, but not all — it
     * would happily be asked for `confirmed`, and a "cancellation" that confirmed
     * a booking and released its vehicle hold is a worse outcome than a refusal.
     */
    public static function notACancellation(BookingStatus $to): self
    {
        return new self(sprintf(
            '%s is not a way for a booking to end, so it cannot be cancelled to it.',
            $to->value,
        ));
    }
}
