<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\BookingPaymentStatus;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\RefundDisbursement;

/**
 * What happened when money went back to a customer.
 *
 * Carries the booking's recomputed position as well as the disbursement,
 * because paying a refund out changes what the booking has been paid — and a
 * caller that had to re-read the booking to find that out would be reading it
 * outside the transaction that changed it.
 */
final class RefundDisbursementResult
{
    public function __construct(
        public readonly Refund $refund,
        public readonly RefundDisbursement $disbursement,
        public readonly Booking $booking,

        /** Confirmed receipts less everything now disbursed. */
        public readonly string $amountPaid,

        public readonly string $balanceDue,
        public readonly BookingPaymentStatus $paymentStatus,
    ) {}
}
