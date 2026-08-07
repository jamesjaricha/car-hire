<?php

declare(strict_types=1);

namespace App\Services\References;

use App\Contracts\ReferenceSequenceContract;
use App\Models\BookingReferenceCounter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hands out gapless numbers, one sequence per prefix.
 *
 * The mechanism is the one that prevents double-booking: lock the row, read
 * under the lock, write, and let the transaction decide when others may
 * proceed. Nothing about the counter is read before the lock is taken, because
 * a value read beforehand is stale by the time it is used.
 *
 * A NOTE ON THE TABLE NAME
 *
 * The rows live in `booking_reference_counters`, which is where they started —
 * it was built in Phase 2 for `BR-00001`. The table has always been generic
 * (a prefix and a next value) and payments now use it too, for the `UP-00001`
 * series given to receipts that arrive without a booking. Renaming the table
 * would be cosmetic churn on live data for no behavioural gain, so the name
 * stays and this note explains it.
 *
 * WHY GAPLESS COSTS SOMETHING, AND WHY IT IS WORTH IT
 *
 * Every allocation for a given prefix serialises on one row. A sequence that
 * tolerated holes would not need the lock and would scale better. But staff read
 * these numbers aloud to customers and match them against bank statements, and
 * "what happened to BR-00042?" is a question nobody should have to answer.
 */
final class ReferenceSequence implements ReferenceSequenceContract
{
    public function next(string $prefix): int
    {
        // If a transaction is already open — the normal case, since this is
        // called while creating a booking or recording a payment — Laravel
        // nests via a savepoint and the lock is held until the outer commit.
        // Called standalone, this provides its own short transaction, which is
        // still safe.
        return DB::transaction(function () use ($prefix): int {
            $counter = $this->lockedCounter($prefix);

            if (! $counter instanceof BookingReferenceCounter) {
                // First ever value for this prefix. insertOrIgnore is race-safe:
                // if another process created the row a moment ago, this quietly
                // does nothing and the re-read picks theirs up.
                DB::table('booking_reference_counters')->insertOrIgnore([
                    'prefix' => $prefix,
                    'next_value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $counter = $this->lockedCounter($prefix);
            }

            if (! $counter instanceof BookingReferenceCounter) {
                throw new RuntimeException(
                    "Could not obtain a reference counter for prefix [{$prefix}]."
                );
            }

            $value = $counter->next_value;

            $counter->forceFill(['next_value' => $value + 1])->save();

            return $value;
        });
    }

    private function lockedCounter(string $prefix): ?BookingReferenceCounter
    {
        return BookingReferenceCounter::query()
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();
    }
}
