<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\BookingReferenceGeneratorContract;
use App\Contracts\ReferenceSequenceContract;

/**
 * Hands out gapless, unique booking references — BR-00001.
 *
 * The locking that makes the sequence safe under concurrency lives in
 * ReferenceSequence, which payments share. This class is only the formatting:
 * which prefix, and how wide.
 */
final class BookingReferenceGenerator implements BookingReferenceGeneratorContract
{
    public function __construct(
        private readonly ReferenceSequenceContract $sequence,
    ) {}

    public function next(): string
    {
        $prefix = $this->prefix();

        return $this->format($prefix, $this->sequence->next($prefix));
    }

    private function format(string $prefix, int $value): string
    {
        $padding = (int) config('carhire.booking_reference_padding', 5);

        // Padding is a minimum, not a maximum: reference 100000 renders in full
        // rather than being truncated once the sequence outgrows five digits.
        return $prefix.'-'.str_pad((string) $value, $padding, '0', STR_PAD_LEFT);
    }

    private function prefix(): string
    {
        return (string) config('carhire.booking_reference_prefix', 'BR');
    }
}
