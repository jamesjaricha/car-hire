<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A payment cannot be written down as described.
 *
 * These are refusals about the money itself rather than about who is asking —
 * permission is StaffPermissionDeniedException's job.
 */
final class PaymentNotRecordableException extends DomainException
{
    public static function amountNotPositive(string $amount): self
    {
        return new self(
            "A payment of [{$amount}] cannot be recorded. Received amounts must be greater than zero; "
            .'to write off or reverse money already taken, raise a refund instead.'
        );
    }

    public static function alreadyAttributed(string $reference): self
    {
        return new self(
            "Payment [{$reference}] already belongs to a booking. "
            .'Moving money between bookings is not a matching decision — reverse it and record it again.'
        );
    }

    public static function confirmedPaymentCannotBeMoved(string $reference): self
    {
        return new self(
            "Payment [{$reference}] has been confirmed and cannot be reattributed. "
            .'Its money is already counted against a booking balance.'
        );
    }

    public static function bookingCannotAcceptPayment(
        string $reference,
        string $bookingReference,
        string $status,
    ): self {
        return new self(sprintf(
            'Payment [%s] cannot be attached to booking [%s]: it is %s. '
            .'Leave the receipt in the unmatched queue, where it can still be traced.',
            $reference,
            $bookingReference,
            lcfirst($status),
        ));
    }

    public static function methodCannotBeUsedManually(string $code): self
    {
        return new self(
            "Payments made by [{$code}] cannot be keyed in by hand. "
            .'Only the offline methods are recorded manually.'
        );
    }
}
