<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The facts a state transition needs to check its guards.
 *
 * Kept separate from the Booking model so the state machine can be exercised
 * without touching the database, and so the guards are explicit about exactly
 * what they depend on rather than reaching into a model for whatever they like.
 *
 * Everything is optional because most transitions have no guards at all. Only
 * releasing a vehicle does, and a caller attempting that transition without
 * supplying the facts is refused rather than waved through.
 */
final readonly class TransitionContext
{
    public function __construct(
        /** Outstanding balance as a bcmath-safe decimal string, e.g. '0.00'. */
        public ?string $balanceDue = null,

        /** Whether the refundable cash security deposit has been recorded. */
        public ?bool $securityDepositCollected = null,

        /**
         * Whether identity documents have been verified at the counter.
         *
         * Not yet enforced: KYC verification lands with the admin panel, so
         * there is nothing to read this from. The field exists so the guard can
         * begin enforcing it the moment that data arrives, rather than the
         * requirement being forgotten. Spec §14.6.
         */
        public ?bool $kycVerified = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function hasOutstandingBalance(): bool
    {
        if ($this->balanceDue === null) {
            return true;
        }

        return bccomp($this->balanceDue, '0', 2) === 1;
    }
}
