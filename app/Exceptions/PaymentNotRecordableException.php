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

    public static function methodCannotBeUsedManually(string $code): self
    {
        return new self(
            "Payments made by [{$code}] cannot be keyed in by hand. "
            .'Only the offline methods are recorded manually.'
        );
    }
}
