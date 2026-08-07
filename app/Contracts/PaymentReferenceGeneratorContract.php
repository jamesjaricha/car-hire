<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Booking;

/**
 * Payment references, in the two shapes the platform needs.
 *
 * Staff read these aloud and match them against bank and mobile money
 * statements, so they are short, unambiguous and never reused.
 */
interface PaymentReferenceGeneratorContract
{
    /**
     * The next reference for a booking's payments: BR-00001-1, then -2.
     *
     * Derived from the booking's own reference so that a customer quoting one
     * number gives staff both facts at once.
     */
    public function forBooking(Booking $booking): string;

    /**
     * A reference for money that arrived without a booking: UP-00001.
     *
     * The guideline §5 promises a queue of these — mobile money statements lag,
     * and till payments often do not carry the reference the customer was
     * given. The receipt still has to be recorded the moment it is seen, so it
     * still needs a reference.
     *
     * It KEEPS this reference when it is later matched to a booking. The number
     * staff wrote down this morning must still find the payment this afternoon.
     */
    public function forUnmatchedReceipt(): string;
}
