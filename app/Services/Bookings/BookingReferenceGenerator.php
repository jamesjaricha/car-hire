<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Contracts\BookingReferenceGeneratorContract;
use App\Models\BookingReferenceCounter;
use Illuminate\Support\Facades\DB;

/**
 * Hands out gapless, unique booking references.
 *
 * The mechanism is the same one that prevents double-booking: lock the row,
 * read under the lock, write, and let the transaction decide when others may
 * proceed. Nothing about the counter is read before the lock is taken, because
 * a value read beforehand is stale by the time it is used.
 */
final class BookingReferenceGenerator implements BookingReferenceGeneratorContract
{
    public function next(): string
    {
        // If a transaction is already open — the normal case, since this is
        // called while creating a booking — Laravel nests via a savepoint and
        // the lock is held until the outer commit. Called standalone, this
        // provides its own short transaction, which is still safe.
        return DB::transaction(function (): string {
            $prefix = $this->prefix();
            $counter = $this->lockedCounter($prefix);

            if (! $counter instanceof BookingReferenceCounter) {
                // First ever reference for this prefix. insertOrIgnore is
                // race-safe: if another process created the row a moment ago,
                // this quietly does nothing and the re-read picks theirs up.
                DB::table('booking_reference_counters')->insertOrIgnore([
                    'prefix' => $prefix,
                    'next_value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $counter = $this->lockedCounter($prefix);
            }

            if (! $counter instanceof BookingReferenceCounter) {
                throw new \RuntimeException(
                    "Could not obtain a booking reference counter for prefix [{$prefix}]."
                );
            }

            $value = $counter->next_value;

            $counter->forceFill(['next_value' => $value + 1])->save();

            return $this->format($prefix, $value);
        });
    }

    private function lockedCounter(string $prefix): ?BookingReferenceCounter
    {
        return BookingReferenceCounter::query()
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();
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
