<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PaymentMethodCode;
use App\Enums\RefundReason;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\User;

/**
 * The first two of spec §9.3's three steps, which belong to two different people.
 *
 * Disbursement is deliberately elsewhere — see `RefundDisbursementServiceContract`.
 */
interface RefundRequestServiceContract
{
    /**
     * Raise a refund against a booking, with its figures computed and frozen.
     *
     * The amount is not a parameter. It comes from `RefundCalculator` and staff
     * cannot alter it.
     */
    public function request(
        User $actor,
        Booking $booking,
        RefundReason $reason,
        PaymentMethodCode $method,
        ?string $notes = null,
    ): Refund;

    /**
     * Sign off a refund. Must not be the person who raised it — spec §9.3.
     */
    public function approve(User $actor, Refund $refund, ?string $notes = null): Refund;

    /**
     * Refuse a refund. The reason is required; the customer will be told it.
     */
    public function reject(User $actor, Refund $refund, string $reason): Refund;
}
