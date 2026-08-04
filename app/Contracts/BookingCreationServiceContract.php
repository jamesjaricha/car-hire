<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\BookingCreationResult;
use App\DataTransferObjects\BookingRequest;
use App\Exceptions\BookingNotPossibleException;
use App\Exceptions\PaymentMethodNotAvailableException;
use App\Exceptions\VehicleNotAvailableException;

/**
 * Turns a checkout submission into a booking.
 *
 * Everything happens in one transaction, and the failure modes are distinct
 * because callers respond to them differently:
 *
 *  - VehicleNotAvailableException — someone took the vehicle while this
 *    customer was filling in the form. Offer alternatives of the same class at
 *    the price they were quoted.
 *  - PaymentMethodNotAvailableException — the method is disabled or too close
 *    to pickup. Re-present the checkout with the methods that are valid.
 *  - BookingNotPossibleException — the request itself is malformed.
 *
 * Nothing partial survives a failure: no orphan customer, no stranded hold, no
 * consumed reference.
 */
interface BookingCreationServiceContract
{
    /**
     * @throws VehicleNotAvailableException
     * @throws PaymentMethodNotAvailableException
     * @throws BookingNotPossibleException
     */
    public function create(BookingRequest $request): BookingCreationResult;
}
