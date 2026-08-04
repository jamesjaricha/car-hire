<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Issues the customer-facing booking reference, e.g. BR-00001.
 *
 * Uniqueness has to hold when two customers submit checkout in the same
 * millisecond. An unlocked read-then-increment is the same class of race as
 * double-booking, and it ends with two people quoting the same reference at the
 * counter — which, given references are how staff match a bank payment to a
 * booking, is worse than it sounds.
 */
interface BookingReferenceGeneratorContract
{
    /**
     * Reserve and return the next reference.
     *
     * Call this inside the transaction that creates the booking. The counter
     * row stays locked until that transaction commits, so a rolled-back
     * booking gives its number back rather than leaving a hole.
     */
    public function next(): string;
}
