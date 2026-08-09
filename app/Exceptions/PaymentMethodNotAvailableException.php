<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Raised when a submitted payment method cannot be used for this booking.
 *
 * Spec §3.2 and §14.2 require this to be enforced by the server, not by the
 * user interface. A greyed-out button stops an honest customer; it does nothing
 * about a request constructed by hand. Every one of these cases must therefore
 * be refused here, at the point the value is used, rather than assumed to have
 * been prevented earlier.
 */
final class PaymentMethodNotAvailableException extends DomainException
{
    public static function unknown(string $code): self
    {
        return new self("There is no payment method with the code [{$code}].");
    }

    public static function notEnabled(string $code): self
    {
        return new self(
            "The payment method [{$code}] is not currently accepted. "
            .'Card payments in particular are visible but deliberately disabled at this stage.'
        );
    }

    public static function tooCloseToPickup(string $code, int $thresholdHours): self
    {
        return new self(sprintf(
            'Pickup is less than %d hours away, so [%s] cannot be used. '
            .'Payment must be made in cash at the branch, and the vehicle is not held.',
            $thresholdHours,
            $code,
        ));
    }

    /**
     * No adapter knows how to handle this method.
     *
     * Today that means one of the card methods. It is a refusal rather than a
     * gap: building an adapter that resolves cleanly and does nothing is how a
     * card payment would one day appear to have been taken.
     */
    public static function noAdapter(string $code): self
    {
        return new self(
            "The payment method [{$code}] cannot be processed: no provider is configured for it."
        );
    }

    /**
     * The operator has switched the method on but never supplied what it needs.
     *
     * A bank transfer with no account number produces instructions telling a
     * customer to send money nowhere. Spec §4 makes the account details part of
     * the method's configuration; until they exist, the method is switched on in
     * name only.
     *
     * Refused for the CUSTOMER, not for staff. `PaymentMethod::isOfferable()`
     * deliberately does not consult this, because a bank transfer that has
     * already landed must still be recordable at the counter — the money
     * arrived whether or not anybody typed an account number into the panel.
     *
     * @param  list<string>  $missing
     */
    public static function notConfigured(string $code, array $missing = []): self
    {
        $detail = $missing === []
            ? 'Its account details have not been entered.'
            : 'Missing: '.implode(', ', $missing).'.';

        return new self(
            "The payment method [{$code}] is switched on but not configured, so it cannot be offered. "
            .$detail
            .' Enter the details in the admin panel under Payment methods.'
        );
    }

    public static function insufficientLeadTime(string $code, int $requiredHours): self
    {
        return new self(sprintf(
            'The payment method [%s] requires at least %d hours before pickup.',
            $code,
            $requiredHours,
        ));
    }
}
