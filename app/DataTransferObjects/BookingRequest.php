<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Vehicle;

/**
 * Everything a checkout submission carries.
 *
 * Note what is absent: no totals, no deadline, no reference. Those are all
 * derived server-side. A request that could name its own price would be a
 * request a customer could edit.
 */
final readonly class BookingRequest
{
    public function __construct(
        public Vehicle $vehicle,
        public DateRange $range,
        public Branch $pickupBranch,
        public Branch $dropoffBranch,
        public CustomerDetails $customer,

        /** Validated against enabled methods server-side, never trusted. */
        public string $paymentMethodCode,

        /** Pay the whole hire now, or the deposit that secures it. Spec §5. */
        public bool $payInFull,

        /** The T&Cs version the customer accepted. Recorded with a timestamp. */
        public string $termsVersion,

        public QuoteOptions $quoteOptions = new QuoteOptions,

        /**
         * A customer whose identity was proven this session by sign-in or OTP.
         * The only thing that permits linking to an existing record — see
         * CustomerResolverContract for why.
         */
        public ?Customer $verifiedCustomer = null,
    ) {}
}
