<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * The result of trying to make sense of something a customer typed into a phone
 * field.
 *
 * `e164` is the only value that may be used for matching customers. `raw` is
 * kept because when a normalisation looks wrong, staff need to see what was
 * actually entered — and because a number we could not parse still has to be
 * stored and shown to someone who will ring it.
 */
final readonly class NormalisedPhone
{
    public function __construct(
        /** What the customer typed, trimmed. Always present. */
        public string $raw,

        /** E.164 form, e.g. +260977123456. Null when unparseable. */
        public ?string $e164,

        /** ISO region the number belongs to, e.g. ZM, GB. Null when unknown. */
        public ?string $region,

        /** Whether libphonenumber considers this a real, dialable number. */
        public bool $isValid,
    ) {}

    public static function unparseable(string $raw): self
    {
        return new self(raw: $raw, e164: null, region: null, isValid: false);
    }

    /**
     * Whether this number is safe to match an existing customer on.
     *
     * An unparseable or invalid number must never be used for matching: it
     * would either match nothing, or — worse — collide with every other
     * unparseable entry and link strangers together.
     */
    public function isMatchable(): bool
    {
        return $this->isValid && $this->e164 !== null;
    }
}
