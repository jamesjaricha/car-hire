<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Contracts\PhoneNormaliserContract;
use App\DataTransferObjects\NormalisedPhone;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Canonicalises phone numbers to E.164 using libphonenumber.
 *
 * A hand-rolled Zambian normaliser would be smaller, but the business expects a
 * substantial share of international customers — business travellers are the
 * core car hire market here — and a `+260`-only implementation would mangle
 * every foreign number, producing a fresh duplicate customer on each visit.
 *
 * The awkward case is a number written with its country code but no plus:
 * `260977123456`. Parsed against the Zambian region that reads as a national
 * number, which is too long to be valid. Rather than guess with prefix
 * heuristics, this parses the obvious way first and, if the result is not a
 * valid number, retries treating the input as international. Whichever
 * interpretation is actually valid wins.
 */
final class PhoneNormaliser implements PhoneNormaliserContract
{
    private readonly PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    public function normalise(string $input, ?string $defaultRegion = null): NormalisedPhone
    {
        $raw = trim($input);

        if ($raw === '') {
            return NormalisedPhone::unparseable($raw);
        }

        $region = $defaultRegion ?? $this->defaultRegion();
        $candidate = $this->tidy($raw);

        $parsed = $this->parseOrNull($candidate, $region);

        // If that reading is not a valid number, the input may be an
        // international number that simply lost its leading plus.
        if ($parsed === null || ! $this->util->isValidNumber($parsed)) {
            $international = $this->parseOrNull('+'.ltrim($candidate, '+'), null);

            if ($international !== null && $this->util->isValidNumber($international)) {
                $parsed = $international;
            }
        }

        if ($parsed === null) {
            return NormalisedPhone::unparseable($raw);
        }

        return new NormalisedPhone(
            raw: $raw,
            e164: $this->util->format($parsed, PhoneNumberFormat::E164),
            region: $this->util->getRegionCodeForNumber($parsed),
            isValid: $this->util->isValidNumber($parsed),
        );
    }

    public function toE164ForMatching(string $input, ?string $defaultRegion = null): ?string
    {
        $normalised = $this->normalise($input, $defaultRegion);

        return $normalised->isMatchable() ? $normalised->e164 : null;
    }

    /**
     * Strip the punctuation people put in phone numbers, and convert a leading
     * `00` international prefix to `+`.
     */
    private function tidy(string $value): string
    {
        $value = preg_replace('/[\s\-().]/', '', $value) ?? $value;

        if (str_starts_with($value, '00')) {
            return '+'.substr($value, 2);
        }

        return $value;
    }

    private function parseOrNull(string $value, ?string $region): ?PhoneNumber
    {
        try {
            return $this->util->parse($value, $region);
        } catch (NumberParseException) {
            return null;
        }
    }

    private function defaultRegion(): string
    {
        return (string) config('carhire.default_phone_region', 'ZM');
    }
}
