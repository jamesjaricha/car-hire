<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentConfirmation;
use App\Support\Money;

/**
 * What confirming one payment did.
 *
 * Carries the recomputed figures rather than leaving the caller to read them
 * back off the booking, so that a confirmation screen shows what this action
 * produced instead of whatever the row happens to say by the time it renders.
 */
final readonly class PaymentConfirmationResult
{
    public function __construct(
        public Payment $payment,
        public PaymentConfirmation $confirmation,
        public Booking $booking,

        /** The sum of every receipt that counts, recomputed from scratch. */
        public string $amountPaid,
        public string $balanceDue,
        public BookingPaymentStatus $paymentStatus,

        public BookingStatus $bookingStatusBefore,
        public BookingStatus $bookingStatusAfter,

        /** Less arrived than this receipt asked for. */
        public bool $hasShortfall,
        public string $shortfallAmount,

        /** More has now been paid than the hire costs. */
        public bool $isOverpaid,
        public string $overpaidAmount,
    ) {}

    public function bookingStatusChanged(): bool
    {
        return $this->bookingStatusBefore !== $this->bookingStatusAfter;
    }

    /**
     * Whether the customer still owes something before the vehicle can go out.
     */
    public function hasOutstandingBalance(): bool
    {
        return Money::isPositive($this->balanceDue);
    }

    /**
     * A booking that took the deposit but is not yet settled.
     *
     * Confirmed enough to hold the car, not enough to release it — spec §5 and
     * §14.3. The two are easy to conflate and a vehicle handed over on the
     * strength of the first is a car given away half paid for.
     */
    public function isConfirmedButUnsettled(): bool
    {
        return $this->paymentStatus === BookingPaymentStatus::PartiallyPaid;
    }
}
