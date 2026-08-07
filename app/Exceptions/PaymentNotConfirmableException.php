<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PaymentStatus;
use App\Models\PaymentConfirmation;
use DomainException;

/**
 * This payment cannot be confirmed as asked.
 *
 * The one that matters is alreadyConfirmed(). Spec §12 requires duplicate
 * confirmation to be structurally impossible, and it is — the unique key on
 * `payment_confirmations` refuses the second writer whatever the application
 * does. This exception is what turns that refusal into a sentence a person can
 * act on, and it is raised from two places on purpose: from the service's own
 * check, which handles the ordinary case, and from the constraint violation,
 * which handles the race. Staff should not be able to tell the difference.
 */
final class PaymentNotConfirmableException extends DomainException
{
    public static function alreadyConfirmed(string $reference, ?PaymentConfirmation $confirmation = null): self
    {
        if (! $confirmation instanceof PaymentConfirmation) {
            return new self("Payment [{$reference}] has already been confirmed.");
        }

        $when = $confirmation->confirmed_at?->setTimezone(
            (string) config('carhire.display_timezone', 'Africa/Lusaka')
        )->format('j M Y H:i');

        return new self(sprintf(
            'Payment [%s] was already confirmed%s%s. Confirming it twice would count the same money twice.',
            $reference,
            $confirmation->confirmedBy()->first()?->name === null ? '' : ' by '.$confirmation->confirmedBy()->first()->name,
            $when === null ? '' : ' on '.$when,
        ));
    }

    public static function notAttributed(string $reference): self
    {
        return new self(
            "Payment [{$reference}] has not been matched to a booking yet. "
            .'Attribute it first: confirming money against no booking would settle nothing.'
        );
    }

    public static function statusForbidsIt(string $reference, PaymentStatus $status): self
    {
        return new self(sprintf(
            'Payment [%s] cannot be confirmed because it is %s.',
            $reference,
            lcfirst($status->label()),
        ));
    }

    /**
     * The payment was attached to a different booking between the caller
     * reading it and the lock being taken. Rare, and better refused than
     * applied to the wrong customer's balance.
     */
    public static function bookingChangedUnderneath(string $reference): self
    {
        return new self(
            "Payment [{$reference}] was reattributed while you were working on it. "
            .'Reload it and check the booking before confirming.'
        );
    }
}
