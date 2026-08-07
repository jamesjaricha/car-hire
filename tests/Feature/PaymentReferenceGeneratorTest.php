<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentReferenceGeneratorContract;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private PaymentReferenceGeneratorContract $references;

    protected function setUp(): void
    {
        parent::setUp();

        $this->references = app(PaymentReferenceGeneratorContract::class);
    }

    public function test_the_first_payment_on_a_booking_takes_suffix_one(): void
    {
        $booking = Booking::factory()->create();

        $this->assertSame(
            $booking->reference.'-1',
            $this->references->forBooking($booking),
        );
    }

    public function test_the_next_payment_takes_the_next_suffix(): void
    {
        $booking = Booking::factory()->create();

        Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-1',
        ]);

        $this->assertSame(
            $booking->reference.'-2',
            $this->references->forBooking($booking),
        );
    }

    /**
     * Derived from the highest suffix rather than from a count. With a count, a
     * missing -2 would hand out -3 a second time, and the unique index would
     * turn a routine confirmation into a 500.
     */
    public function test_a_gap_in_the_sequence_does_not_cause_a_collision(): void
    {
        $booking = Booking::factory()->create();

        Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-1',
        ]);
        Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-3',
        ]);

        $this->assertSame(
            $booking->reference.'-4',
            $this->references->forBooking($booking),
        );
    }

    /**
     * A receipt that arrived unmatched keeps its UP- reference when it is
     * attributed to a booking. It has no suffix, and must not be mistaken for
     * one — nor shift the numbering of the payments that follow it.
     */
    public function test_a_matched_in_receipt_does_not_disturb_the_suffixes(): void
    {
        $booking = Booking::factory()->create();

        Payment::factory()->forBooking($booking)->create([
            'payment_reference' => $booking->reference.'-1',
        ]);

        // Arrived without a booking, later attributed to this one.
        Payment::factory()->forBooking($booking)->create([
            'payment_reference' => 'UP-00007',
        ]);

        $this->assertSame(
            $booking->reference.'-2',
            $this->references->forBooking($booking),
        );
    }

    public function test_suffixes_are_counted_per_booking_not_globally(): void
    {
        $first = Booking::factory()->create();
        $second = Booking::factory()->create();

        Payment::factory()->forBooking($first)->create([
            'payment_reference' => $first->reference.'-1',
        ]);
        Payment::factory()->forBooking($first)->create([
            'payment_reference' => $first->reference.'-2',
        ]);

        $this->assertSame($second->reference.'-1', $this->references->forBooking($second));
        $this->assertSame($first->reference.'-3', $this->references->forBooking($first));
    }

    /**
     * Money that arrives with nothing to attribute it to still has to be
     * recorded, so it still needs a reference. Guideline §5.
     */
    public function test_unmatched_receipts_take_their_own_padded_sequence(): void
    {
        $this->assertSame('UP-00001', $this->references->forUnmatchedReceipt());
        $this->assertSame('UP-00002', $this->references->forUnmatchedReceipt());
        $this->assertSame('UP-00003', $this->references->forUnmatchedReceipt());
    }

    /**
     * The two series are independent. Allocating one must not consume the
     * other, or the numbers staff read aloud stop being predictable.
     */
    public function test_the_unmatched_series_is_independent_of_booking_references(): void
    {
        $booking = Booking::factory()->create();

        $this->references->forUnmatchedReceipt();
        $this->references->forUnmatchedReceipt();

        $this->assertSame($booking->reference.'-1', $this->references->forBooking($booking));
        $this->assertSame('UP-00003', $this->references->forUnmatchedReceipt());
    }

    /**
     * A reference is only reserved when it is written. The generator reads what
     * exists; calling it twice without recording anything is not an allocation,
     * and the caller that finally inserts is the one that settles the number.
     */
    public function test_it_reports_the_same_next_suffix_until_a_payment_is_written(): void
    {
        $booking = Booking::factory()->create();

        $first = $this->references->forBooking($booking);
        $second = $this->references->forBooking($booking);

        $this->assertSame($first, $second);

        Payment::factory()->forBooking($booking)->create(['payment_reference' => $first]);

        $this->assertSame($booking->reference.'-2', $this->references->forBooking($booking));
    }
}
