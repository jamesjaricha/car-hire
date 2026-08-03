<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Exceptions\InvalidDateRangeException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A hire window, treated as half-open: [start, end).
 *
 * Half-open matters. A hire that ends at 10:00 and one that begins at 10:00 do
 * not overlap. Using closed ranges here would make every boundary comparison
 * ambiguous, and a past project lost the final day of every range to exactly
 * that ambiguity.
 *
 * Both instants are UTC. Conversion to Zambian local time is a display concern
 * and happens at the edge, never in here.
 */
final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end->lessThanOrEqualTo($start)) {
            throw InvalidDateRangeException::endNotAfterStart($start, $end);
        }
    }

    public static function of(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        return new self(
            CarbonImmutable::parse($start)->utc(),
            CarbonImmutable::parse($end)->utc(),
        );
    }

    /**
     * Days chargeable for this hire.
     *
     * A hire day is 24 hours and part days round up, which is the near-universal
     * car hire convention: collecting at 09:00 on Monday and returning at 11:00
     * on Tuesday is two days, not one. A range shorter than a day still charges
     * one day.
     */
    public function chargeableDays(): int
    {
        $minutes = $this->start->diffInMinutes($this->end);

        return max(1, (int) ceil($minutes / (24 * 60)));
    }

    /**
     * A copy with both ends pushed outward by the turnaround buffer.
     *
     * Used when testing one hire against another: expanding both sides
     * guarantees at least `minutes` of clear time between any two hires,
     * whichever order they fall in.
     */
    public function paddedBy(int $minutes): self
    {
        if ($minutes <= 0) {
            return $this;
        }

        return new self(
            $this->start->subMinutes($minutes),
            $this->end->addMinutes($minutes),
        );
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end)
            && $this->end->greaterThan($other->start);
    }

    public function containsInstant(CarbonImmutable $instant): bool
    {
        return $instant->greaterThanOrEqualTo($this->start)
            && $instant->lessThan($this->end);
    }

    public function __toString(): string
    {
        return $this->start->toIso8601String().' → '.$this->end->toIso8601String();
    }
}
