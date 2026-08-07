<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentConfirmation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE STRUCTURAL GUARANTEE.
     *
     * Spec §12 requires duplicate confirmation to be impossible rather than
     * discouraged. This asserts it at the database, with no service involved
     * and no application check in the way — the second insert must fail even
     * when nothing has looked first.
     *
     * The two-process race that proves it under real contention comes with
     * PaymentConfirmationService. This is the constraint that race relies on.
     */
    public function test_a_payment_cannot_be_confirmed_twice(): void
    {
        $payment = Payment::factory()->confirmed()->create();
        $clerk = User::factory()->create();

        PaymentConfirmation::factory()->forPayment($payment)->by($clerk)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        PaymentConfirmation::factory()->forPayment($payment)->by($clerk)->create();
    }

    public function test_a_payment_reference_cannot_be_reused(): void
    {
        Payment::factory()->create(['payment_reference' => 'BR-00001-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        Payment::factory()->create(['payment_reference' => 'BR-00001-1']);
    }

    /**
     * The unmatched payments queue the guideline §5 insists on having from day
     * one. Money arrives that nobody can attribute yet; it still has to be
     * recorded.
     */
    public function test_an_unmatched_payment_belongs_to_no_booking(): void
    {
        $matched = Payment::factory()->create();
        $unmatched = Payment::factory()->unmatched('1155.00')->create();

        $this->assertTrue($matched->isMatched());
        $this->assertFalse($unmatched->isMatched());
        $this->assertNull($unmatched->booking_id);
        $this->assertNull($unmatched->operator_id);

        $queue = Payment::query()->unmatched()->pluck('id')->all();

        $this->assertSame([$unmatched->getKey()], $queue);
    }

    /**
     * A shortfall is measured against what was asked for at the time, not
     * against the booking's balance — that figure moves as other payments are
     * confirmed, so the same short payment would look different depending on
     * when the question was asked.
     */
    public function test_it_detects_a_short_payment(): void
    {
        $payment = Payment::factory()->create([
            'expected_amount' => '1155.00',
            'amount' => '1000.00',
        ]);

        $this->assertTrue($payment->hasShortfall());
        $this->assertSame('155.00', $payment->shortfallAmount());
    }

    public function test_an_exact_or_generous_payment_is_not_a_shortfall(): void
    {
        $exact = Payment::factory()->create([
            'expected_amount' => '1155.00',
            'amount' => '1155.00',
        ]);

        $over = Payment::factory()->create([
            'expected_amount' => '1155.00',
            'amount' => '1200.00',
        ]);

        $this->assertFalse($exact->hasShortfall());
        $this->assertSame('0.00', $exact->shortfallAmount());

        $this->assertFalse($over->hasShortfall());
        $this->assertSame('0.00', $over->shortfallAmount());
    }

    /**
     * Unpaid is not underpaid.
     *
     * Every booking awaiting payment starts at zero against its full expected
     * amount, so a literal comparison reports every one of them as short by its
     * entire total. "The customer has not paid" is chased; "the customer sent
     * too little" is reconciled. They are different problems, and the queue
     * that exists to catch the second is useless if it is full of the first.
     */
    public function test_a_receipt_with_nothing_against_it_is_unpaid_not_short(): void
    {
        $awaiting = Payment::factory()->create([
            'expected_amount' => '1155.00',
            'amount' => '0.00',
        ]);

        $this->assertFalse($awaiting->hasShortfall());
        $this->assertSame('0.00', $awaiting->shortfallAmount());

        // One ngwee in, however, and it is genuinely short.
        $barelyPaid = Payment::factory()->create([
            'expected_amount' => '1155.00',
            'amount' => '0.01',
        ]);

        $this->assertTrue($barelyPaid->hasShortfall());
        $this->assertSame('1154.99', $barelyPaid->shortfallAmount());
    }

    /**
     * An unmatched receipt has no expectation to fall short of. It is money
     * nobody has attributed, not money that is missing.
     */
    public function test_an_unmatched_payment_is_never_a_shortfall(): void
    {
        $payment = Payment::factory()->unmatched('50.00')->create();

        $this->assertNull($payment->expected_amount);
        $this->assertFalse($payment->hasShortfall());
    }

    public function test_only_counted_statuses_appear_in_the_counted_scope(): void
    {
        $booking = Booking::factory()->create();

        $confirmed = Payment::factory()->forBooking($booking)->confirmed('500.00')->create();
        $refundPending = Payment::factory()->forBooking($booking)->create([
            'status' => PaymentStatus::RefundPending,
            'amount' => '100.00',
        ]);

        // None of these are money in hand.
        Payment::factory()->forBooking($booking)->create(['amount' => '900.00']);
        Payment::factory()->forBooking($booking)->proofSubmitted()->create(['amount' => '900.00']);
        Payment::factory()->forBooking($booking)->expired()->create(['amount' => '900.00']);
        Payment::factory()->forBooking($booking)->create([
            'status' => PaymentStatus::Refunded,
            'amount' => '900.00',
        ]);

        $counted = Payment::query()->counted()->orderBy('id')->pluck('id')->all();

        $this->assertSame(
            [$confirmed->getKey(), $refundPending->getKey()],
            $counted,
        );
    }

    /**
     * How `amount_paid` will be recomputed. Two things are load-bearing:
     * ->reorder() before the aggregate, and Money::of() after it.
     */
    public function test_the_paid_total_is_the_sum_of_counted_receipts(): void
    {
        $booking = Booking::factory()->create();

        Payment::factory()->forBooking($booking)->confirmed('1155.00')->create();
        Payment::factory()->forBooking($booking)->confirmed('500.00')->create();

        // Not counted, and deliberately a round number so that if it leaked
        // into the total the failure would be obvious rather than plausible.
        Payment::factory()->forBooking($booking)->create(['amount' => '1000.00']);

        $total = Money::of($booking->countedPayments()->reorder()->sum('amount'));

        // Asserted as an exact string. SQL returns '1655', unscaled; the
        // difference from '1655.00' is invisible to == and fatal to assertSame.
        $this->assertSame('1655.00', $total);
    }

    public function test_a_booking_with_no_receipts_has_paid_nothing(): void
    {
        $booking = Booking::factory()->create();

        $this->assertSame(
            '0.00',
            Money::of($booking->countedPayments()->reorder()->sum('amount')),
        );
    }

    public function test_a_payment_resolves_its_booking_operator_and_confirmation(): void
    {
        $booking = Booking::factory()->create();
        $clerk = User::factory()->create();

        $payment = Payment::factory()->forBooking($booking)->confirmed()->create();
        PaymentConfirmation::factory()->forPayment($payment)->by($clerk)->create();

        $payment->load(['booking', 'operator', 'confirmation.confirmedBy']);

        $this->assertTrue($payment->booking->is($booking));
        $this->assertSame($booking->operator_id, $payment->operator->getKey());
        $this->assertTrue($payment->confirmation->confirmedBy->is($clerk));
    }

    /**
     * The factory takes the operator from the booking rather than generating
     * its own. A payment sitting under a different operator from its booking
     * is a state the permission checks should never have to reason about.
     */
    public function test_a_payment_inherits_its_bookings_operator(): void
    {
        $booking = Booking::factory()->create();

        $payment = Payment::factory()->create(['booking_id' => $booking->getKey()]);

        $this->assertSame($booking->operator_id, $payment->operator_id);
    }

    public function test_money_columns_come_back_as_scaled_strings(): void
    {
        $payment = Payment::factory()->create([
            'amount' => '300',
            'expected_amount' => '1155',
        ]);

        $payment->refresh();

        $this->assertSame('300.00', $payment->amount);
        $this->assertSame('1155.00', $payment->expected_amount);
    }
}
