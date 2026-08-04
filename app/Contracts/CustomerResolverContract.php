<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\CustomerDetails;
use App\DataTransferObjects\CustomerResolutionResult;
use App\Models\Customer;

/**
 * Decides which customer record a booking attaches to.
 *
 * This implements spec §1.4, which the specification marks security-critical.
 * The rules, in short:
 *
 *  - Matching an existing record NEVER links to it automatically.
 *  - Linking happens only after a successful sign-in or an OTP verification.
 *  - Otherwise a new, unlinked record is created for staff to merge later.
 *  - If the email matches one customer and the phone matches another, link to
 *    neither and flag it.
 *  - Nothing about the outcome may be revealed to the customer.
 */
interface CustomerResolverContract
{
    /**
     * @param  Customer|null  $verifiedCustomer  A customer whose identity has
     *                                           already been proven this session, by sign-in or OTP. Passing one
     *                                           is the ONLY way linking occurs. Never pass a customer found by
     *                                           looking up the submitted details — that is the silent link the
     *                                           specification forbids.
     */
    public function resolveForCheckout(
        CustomerDetails $details,
        ?Customer $verifiedCustomer = null,
    ): CustomerResolutionResult;
}
