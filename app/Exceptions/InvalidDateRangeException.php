<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Raised when a hire window makes no sense — a return before a collection,
 * or a zero-length hire.
 */
final class InvalidDateRangeException extends InvalidArgumentException
{
    public static function endNotAfterStart(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self(sprintf(
            'A hire must end after it starts. Given start [%s] and end [%s].',
            $start->toIso8601String(),
            $end->toIso8601String(),
        ));
    }
}
