<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\CustomerResolutionOutcome;
use App\Models\Customer;

/**
 * The customer a booking will be attached to, and how we arrived at them.
 *
 * ⚠ `anExistingRecordMatched` is server-side information ONLY.
 *
 * Spec §1.4 forbids revealing that an account exists. If checkout renders a
 * different screen, a different message, or even a different set of buttons
 * depending on this flag, it has disclosed exactly what the specification says
 * must not be disclosed — an attacker can then enumerate which email addresses
 * have accounts simply by starting checkouts.
 *
 * The sign-in and continue-as-guest options must therefore be offered
 * identically to everyone, whether or not anything matched.
 */
final readonly class CustomerResolutionResult
{
    public function __construct(
        public Customer $customer,
        public CustomerResolutionOutcome $outcome,

        /** Never branch customer-facing output on this. See above. */
        public bool $anExistingRecordMatched,

        /** Set when the email matched one customer and the phone another. */
        public bool $hasConflict = false,
    ) {}

    public function requiresStaffAttention(): bool
    {
        return $this->hasConflict;
    }
}
