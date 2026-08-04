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

    public static function insufficientLeadTime(string $code, int $requiredHours): self
    {
        return new self(sprintf(
            'The payment method [%s] requires at least %d hours before pickup.',
            $code,
            $requiredHours,
        ));
    }
}
