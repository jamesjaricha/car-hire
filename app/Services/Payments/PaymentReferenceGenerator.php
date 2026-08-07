<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentReferenceGeneratorContract;
use App\Contracts\ReferenceSequenceContract;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BR-00001-1 for a booking's payments, UP-00001 for money that arrived without
 * one.
 *
 * WHY THE BOOKING SEQUENCE IS NOT A COUNTER ROW
 *
 * Booking references come from a counter table because they are global and
 * gapless. A payment's suffix is neither: it is per booking, and it can be
 * derived from the payments that already exist. A counter per booking would be
 * a row per booking to maintain, and a second source of truth to disagree with
 * the payments themselves.
 *
 * So the suffix is read from the existing payments — which makes allocation a
 * read-modify-write, and therefore a race, and therefore something that needs a
 * lock. Two payments recorded against one booking in the same instant would
 * otherwise both read "the highest is 1" and both claim -2. The unique index on
 * `payment_reference` would catch that, but as a 500 rather than a reference.
 *
 * The lock is taken on the BOOKING row, not on the payments. There is no row to
 * lock for a payment that does not exist yet, and locking the booking gives
 * every allocation for that booking the same queue.
 */
final class PaymentReferenceGenerator implements PaymentReferenceGeneratorContract
{
    public function __construct(
        private readonly ReferenceSequenceContract $sequence,
    ) {}

    public function forBooking(Booking $booking): string
    {
        return DB::transaction(function () use ($booking): string {
            // The lock comes first. Nothing about existing payments is read
            // before this line, because anything read before it is stale by the
            // time we act on it.
            $locked = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->first(['id', 'reference']);

            if (! $locked instanceof Booking) {
                throw new RuntimeException(
                    "Cannot allocate a payment reference: booking [{$booking->getKey()}] no longer exists."
                );
            }

            // A locking read, for the same reason the overlap check in
            // VehicleHoldService is one. The connection runs at READ COMMITTED,
            // so a plain SELECT here would in fact see fresh data — but that is
            // a property of a setting in config/database.php, not of this code,
            // and this code should still be correct if that setting changes.
            $existing = Payment::query()
                ->where('booking_id', $locked->getKey())
                ->lockForUpdate()
                ->get(['payment_reference'])
                ->pluck('payment_reference')
                ->all();

            return $locked->reference.'-'.$this->nextSuffix($locked->reference, $existing);
        });
    }

    public function forUnmatchedReceipt(): string
    {
        $prefix = $this->unmatchedPrefix();

        return $prefix.'-'.str_pad(
            (string) $this->sequence->next($prefix),
            $this->padding(),
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * One past the highest suffix already used on this booking.
     *
     * Derived from the highest rather than from a count, so that it still
     * behaves if a reference is ever missing from the middle — and so that a
     * matched-in receipt, which keeps its own UP-00001 reference and has no
     * suffix at all, cannot shift the numbering of everything after it.
     *
     * @param  list<string>  $references
     */
    private function nextSuffix(string $bookingReference, array $references): int
    {
        $prefix = $bookingReference.'-';
        $highest = 0;

        foreach ($references as $reference) {
            if (! str_starts_with($reference, $prefix)) {
                // Belongs to another series — a receipt that arrived unmatched
                // and was attributed here later.
                continue;
            }

            $suffix = substr($reference, strlen($prefix));

            if ($suffix === '' || ! ctype_digit($suffix)) {
                continue;
            }

            $highest = max($highest, (int) $suffix);
        }

        return $highest + 1;
    }

    private function unmatchedPrefix(): string
    {
        return (string) config('carhire.unmatched_payment_reference_prefix', 'UP');
    }

    private function padding(): int
    {
        return (int) config('carhire.booking_reference_padding', 5);
    }
}
