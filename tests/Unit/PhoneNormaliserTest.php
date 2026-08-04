<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PhoneNormaliserContract;
use Tests\TestCase;

final class PhoneNormaliserTest extends TestCase
{
    private PhoneNormaliserContract $phones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phones = app(PhoneNormaliserContract::class);
    }

    /**
     * Spec §1.4, verbatim: these three must reach one stored value.
     */
    public function test_the_three_zambian_forms_normalise_to_one_value(): void
    {
        foreach (['0977123456', '260977123456', '+260977123456'] as $input) {
            $this->assertSame(
                '+260977123456',
                $this->phones->normalise($input)->e164,
                "Input [{$input}] did not normalise to the canonical form."
            );
        }
    }

    public function test_punctuation_and_spacing_are_ignored(): void
    {
        foreach (['+260 977 123 456', '0977 123 456', '(0977) 123-456', '+260-977-123-456'] as $input) {
            $this->assertSame(
                '+260977123456',
                $this->phones->normalise($input)->e164,
                "Input [{$input}] did not normalise to the canonical form."
            );
        }
    }

    public function test_a_double_zero_international_prefix_is_understood(): void
    {
        $this->assertSame('+260977123456', $this->phones->normalise('00260977123456')->e164);
    }

    public function test_a_zambian_number_reports_its_region(): void
    {
        $normalised = $this->phones->normalise('0977123456');

        $this->assertSame('ZM', $normalised->region);
        $this->assertTrue($normalised->isValid);
    }

    public function test_international_numbers_survive_intact(): void
    {
        // Business travellers are the core customer. A Zambia-only normaliser
        // would mangle these and create a fresh duplicate on every visit.
        //
        // E.164 round-tripping is what the platform actually depends on, since
        // that is the only value customers are matched on. Region is stored for
        // staff context, not used for matching, so it is not asserted here.
        $internationalNumbers = [
            '+447911123456',   // British Isles
            '+27821234567',    // South Africa
            '+254711123456',   // Kenya
        ];

        foreach ($internationalNumbers as $number) {
            $normalised = $this->phones->normalise($number);

            $this->assertSame($number, $normalised->e164, "[{$number}] did not survive normalisation.");
            $this->assertTrue($normalised->isMatchable(), "[{$number}] should be usable for matching.");
        }
    }

    public function test_the_region_of_our_own_market_is_identified(): void
    {
        $southAfrica = $this->phones->normalise('+27821234567');

        $this->assertSame('ZA', $southAfrica->region);
    }

    public function test_numbering_plan_subtleties_are_left_to_the_library(): void
    {
        // +44 7911 looks British but is allocated to Guernsey. A hand-rolled
        // normaliser would have got this wrong and nobody would have noticed.
        // This test exists to record why the library earns its place rather
        // than to assert anything the platform depends on.
        $this->assertSame('GG', $this->phones->normalise('+447911123456')->region);
    }

    public function test_the_raw_input_is_always_preserved(): void
    {
        // Staff need to see what was actually typed when a normalisation
        // looks wrong.
        $this->assertSame('0977 123 456', $this->phones->normalise('  0977 123 456  ')->raw);
    }

    public function test_nonsense_is_reported_rather_than_guessed_at(): void
    {
        foreach (['', '   ', 'not a number', '12'] as $input) {
            $normalised = $this->phones->normalise($input);

            $this->assertFalse(
                $normalised->isMatchable(),
                "Input [{$input}] should not be usable for matching."
            );
        }
    }

    public function test_an_unmatchable_number_yields_null_for_lookups(): void
    {
        // The important guarantee: a caller cannot accidentally query
        // phone_e164 with junk and match every other junk entry, linking
        // strangers to one another.
        $this->assertNull($this->phones->toE164ForMatching('not a number'));
        $this->assertNull($this->phones->toE164ForMatching(''));
    }

    public function test_lookups_use_the_same_normalisation_as_writes(): void
    {
        // The guideline's specific warning: normalise on save but query with
        // raw input and matching fails silently, forever.
        $stored = $this->phones->normalise('+260977123456')->e164;

        foreach (['0977123456', '260977123456', '+260 977 123 456'] as $whatTheUserTypedLater) {
            $this->assertSame(
                $stored,
                $this->phones->toE164ForMatching($whatTheUserTypedLater),
                "Lookup for [{$whatTheUserTypedLater}] would not have found the stored record."
            );
        }
    }

    public function test_an_explicit_region_overrides_the_default(): void
    {
        $this->assertSame(
            '+447911123456',
            $this->phones->normalise('07911123456', 'GB')->e164,
        );
    }
}
