<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\VehicleHold;

/**
 * What came of a checkout submission.
 *
 * `hold` is null for short-notice bookings, and that is not an omission: spec
 * §8.2 says a booking made within four hours of pickup places no hold and that
 * availability is first-come at the counter. The customer has a booking; they
 * do not have a guaranteed vehicle, and the confirmation must say so.
 *
 * `payment` is never null. Spec §14.3 requires every offline booking to produce
 * a payment record and a unique payment reference, including a short-notice one
 * that will be settled at the counter — the customer still has to be told what
 * to pay and what to quote.
 */
final readonly class BookingCreationResult
{
    public function __construct(
        public Booking $booking,
        public Quote $quote,
        public CustomerResolutionResult $customerResolution,
        public PaymentWindow $paymentWindow,
        public Payment $payment,

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
