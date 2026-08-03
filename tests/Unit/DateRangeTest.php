<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataTransferObjects\DateRange;
use App\Exceptions\InvalidDateRangeException;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class DateRangeTest extends TestCase
{
    public function test_it_rejects_an_end_before_the_start(): void
    {
        $this->expectException(InvalidDateRangeException::class);

        DateRange::of('2026-09-01T10:00:00Z', '2026-08-31T10:00:00Z');
    }

    public function test_it_rejects_a_zero_length_hire(): void
    {
        $this->expectException(InvalidDateRangeException::class);

        DateRange::of('2026-09-01T10:00:00Z', '2026-09-01T10:00:00Z');
    }

    public function test_exactly_twenty_four_hours_is_one_chargeable_day(): void
    {
        $range = DateRange::of('2026-09-01T09:00:00Z', '2026-09-02T09:00:00Z');

        $this->assertSame(1, $range->chargeableDays());
    }

    public function test_a_part_day_rounds_up(): void
    {
        // Collected 09:00 Monday, returned 11:00 Tuesday: two days, not one.
        $range = DateRange::of('2026-09-01T09:00:00Z', '2026-09-02T11:00:00Z');

        $this->assertSame(2, $range->chargeableDays());
    }

    public function test_a_hire_shorter_than_a_day_still_charges_one_day(): void
    {
        $range = DateRange::of('2026-09-01T09:00:00Z', '2026-09-01T11:30:00Z');

        $this->assertSame(1, $range->chargeableDays());
    }

    public function test_ranges_that_merely_touch_do_not_overlap(): void
    {
        // Half-open: one hire ending exactly as another begins is not a clash.
        $earlier = DateRange::of('2026-09-01T08:00:00Z', '2026-09-01T12:00:00Z');
        $later = DateRange::of('2026-09-01T12:00:00Z', '2026-09-01T16:00:00Z');

        $this->assertFalse($earlier->overlaps($later));
        $this->assertFalse($later->overlaps($earlier));
    }

    public function test_genuinely_overlapping_ranges_are_detected(): void
    {
        $a = DateRange::of('2026-09-01T08:00:00Z', '2026-09-01T13:00:00Z');
        $b = DateRange::of('2026-09-01T12:00:00Z', '2026-09-01T16:00:00Z');

        $this->assertTrue($a->overlaps($b));
        $this->assertTrue($b->overlaps($a));
    }

    public function test_padding_expands_both_ends(): void
    {
        $range = DateRange::of('2026-09-01T10:00:00Z', '2026-09-01T14:00:00Z');
        $padded = $range->paddedBy(120);

        $this->assertSame('2026-09-01T08:00:00+00:00', $padded->start->toIso8601String());
        $this->assertSame('2026-09-01T16:00:00+00:00', $padded->end->toIso8601String());
    }

    public function test_padding_by_zero_returns_an_equivalent_range(): void
    {
        $range = DateRange::of('2026-09-01T10:00:00Z', '2026-09-01T14:00:00Z');

        $this->assertTrue($range->start->equalTo($range->paddedBy(0)->start));
        $this->assertTrue($range->end->equalTo($range->paddedBy(0)->end));
    }

    public function test_local_zambian_times_are_stored_as_utc(): void
    {
        // 23:30 in Lusaka on the 1st is 21:30 UTC on the 1st. This is the case
        // the developer guideline calls out: a late-evening booking for a
        // next-morning pickup.
        $range = DateRange::of(
            CarbonImmutable::parse('2026-09-01 23:30:00', 'Africa/Lusaka'),
            CarbonImmutable::parse('2026-09-03 08:00:00', 'Africa/Lusaka'),
        );

        $this->assertSame('2026-09-01T21:30:00+00:00', $range->start->toIso8601String());
        $this->assertSame('2026-09-03T06:00:00+00:00', $range->end->toIso8601String());
    }
}
