<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Booking;
use App\Models\VehicleHold;

/**
 * What came of a checkout submission.
 *
 * `hold` is null for short-notice bookings, and that is not an omission: spec
 * §8.2 says a booking made within four hours of pickup places no hold and that
 * availability is first-come at the counter. The customer has a booking; they
 * do not have a guaranteed vehicle, and the confirmation must say so.
 */
final readonly class BookingCreationResult
{
    public function __construct(
        public Booking $booking,
        public Quote $quote,
        public CustomerResolutionResult $customerResolution,
        public PaymentWindow $paymentWindow,

        /** Null when no vehicle was claimed. See above. */
        public ?VehicleHold $hold = null,
    ) {}

    /**
     * Whether a specific vehicle is actually being kept for this customer.
     */
    public function vehicleIsHeld(): bool
    {
        return $this->hold instanceof VehicleHold;
    }

    /**
     * What the customer has been told to pay, given the option they chose.
     */
    public function amountDueNow(): string
    {
        return $this->quote->amountDueNow($this->booking->pay_in_full);
    }
}
