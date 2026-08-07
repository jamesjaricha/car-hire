<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * What one run of the expiry sweep did.
 *
 * Counted rather than merely logged, because the guideline is explicit that a
 * dead expiry job is one of the ways this platform fails quietly: vehicles stay
 * claimed and inventory disappears with nothing to show for it. A run that
 * reports its numbers can be watched; one that returns void cannot.
 */
final readonly class ExpirySweepResult
{
    public function __construct(
        /** Bookings cancelled for non-payment. */
        public int $expired = 0,

        /**
         * Bookings left alone because the customer has paid something.
         *
         * These need a person. Cancelling a booking that is holding somebody's
         * money is a decision about a refund, and there is no unattended answer
         * to it that is not worse than waiting.
         */
        public int $leftForStaff = 0,

        /**
         * Lapsed holds released, including any belonging to bookings that were
         * already dealt with. The safety net the guideline asks for.
         */
        public int $holdsReleased = 0,
    ) {}

    public function didNothing(): bool
    {
        return $this->expired === 0
            && $this->leftForStaff === 0
            && $this->holdsReleased === 0;
    }

    public function summary(): string
    {
        return sprintf(
            '%d booking(s) cancelled for non-payment, %d left for staff, %d hold(s) released.',
            $this->expired,
            $this->leftForStaff,
            $this->holdsReleased,
        );
    }
}
