<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The three things a customer supplies at final checkout.
 *
 * Spec §1.3: nothing more than this is asked for, and it is asked for only at
 * the last step. No account, no registration, no details earlier in the flow.
 */
final readonly class CustomerDetails
{
    public function __construct(
        public string $fullName,
        public string $email,
        /** Raw, as typed. Normalisation is the resolver's job, not the caller's. */
        public string $phone,
    ) {}

    /**
     * Email lowercased and trimmed, which is the form stored and matched on.
     *
     * Stored already-normalised so lookups can use the index rather than
     * wrapping the column in LOWER(), which would prevent its use.
     */
    public function normalisedEmail(): string
    {
        return mb_strtolower(trim($this->email));
    }

    public function trimmedName(): string
    {
        return trim($this->fullName);
    }
}
