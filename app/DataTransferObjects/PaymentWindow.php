<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * When a booking must be paid for, and whether a vehicle is held meanwhile.
 *
 * Two genuinely different outcomes live in this one object:
 *
 *  - A normal booking: a deadline, a held vehicle, and a reminder before the
 *    deadline lapses.
 *  - A short-notice booking (pickup under four hours away, spec §8.2): no
 *    deadline, no hold, and availability is first-come at the counter. The
 *    customer simply pays when they arrive.
 *
 * `deadlineAt` being null is therefore meaningful, not missing data. Callers
 * must check `placesHold` before assuming a vehicle has been claimed — a
 * short-notice booking that quietly went through the hold path would claim a
 * vehicle the specification says stays available to whoever turns up first.
 */
final readonly class PaymentWindow
{
    public function __construct(
        /** Null for short-notice bookings, which are paid at the counter. */
        public ?CarbonImmutable $deadlineAt,

        public bool $placesHold,

        public bool $isShortNotice,

        /** When to nudge the customer. Null when there is no deadline. */
        public ?CarbonImmutable $reminderAt = null,
    ) {}

    /**
     * Pickup is too close for remote payment to be verified in time.
     */
    public static function payAtBranch(): self
    {
        return new self(
            deadlineAt: null,
            placesHold: false,
            isShortNotice: true,
            reminderAt: null,
        );
    }

    public function requiresRemotePayment(): bool
    {
        return ! $this->isShortNotice;
    }
}
