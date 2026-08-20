<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\BookingNotifierContract;
use App\DataTransferObjects\PaymentConfirmationResult;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Mail\BookingSubmittedMail;
use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentConfirmation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Spec §13 email, and the ways it must not misbehave.
 *
 * ⚠ WHY THE MAILABLES ARE RENDERED EXPLICITLY HERE
 *
 * `BookingNotifier` deliberately SWALLOWS its own failures. That is correct — a
 * committed booking must not become a 500 because a mail server is down — but
 * it means a broken Blade template, a renamed route or a missing variable would
 * be **invisible**: the booking succeeds, nothing errors, no email arrives, and
 * every test stays green.
 *
 * `MAIL_MAILER=array` in this suite renders views for real, so a template fault
 * WOULD throw — straight into that catch. Rendering the mailables directly is
 * the only thing standing between a typo in a view and silence in production.
 * This project has found four faults in Blade by looking at rendered output;
 * none of them were caught by an assertion about behaviour.
 *
 * `bookingSubmitted()` dispatch is exercised through the real service in
 * `BookingCreationServiceTest`, which now calls the notifier on every booking
 * it makes. Building a `BookingCreationResult` by hand needs a Quote, a
 * PaymentWindow and a CustomerResolutionResult, and a fixture that elaborate
 * tests the fixture rather than the code.
 */
final class BookingNotifierTest extends TestCase
{
    use RefreshDatabase;

    // --- Who gets written to ------------------------------------------------

    public function test_a_confirmed_payment_emails_the_customer(): void
    {
        Mail::fake();

        $booking = $this->booking(['email' => 'joseph@example.com']);

        app(BookingNotifierContract::class)->paymentConfirmed(
            $this->confirmationResult($booking, balanceDue: Money::ZERO),
        );

        Mail::assertQueued(
            PaymentConfirmedMail::class,
            fn (PaymentConfirmedMail $mail): bool => $mail->hasTo('joseph@example.com'),
        );
    }

    /**
     * A customer with no usable address is skipped without complaint.
     *
     * ⚠ `customers.email` is NOT NULL, so the empty string is the only shape
     * this case can actually take in the database — a null would be refused by
     * the schema before the guard ever saw it. Written this way rather than
     * with `null` because the first attempt asserted a state that cannot exist,
     * which tests the fixture rather than the code.
     *
     * The guard stays regardless: `$booking->customer` is reached through a
     * nullable relation, and a blank address must not become `Mail::to('')`.
     */
    public function test_a_customer_without_an_email_is_skipped_quietly(): void
    {
        Mail::fake();

        $booking = $this->booking(['email' => '']);

        app(BookingNotifierContract::class)->paymentConfirmed(
            $this->confirmationResult($booking, balanceDue: Money::ZERO),
        );

        Mail::assertNothingQueued();
    }

    // --- What the emails actually say ---------------------------------------

    /**
     * ⚠ SPEC §7.3. This email must never say the booking is confirmed.
     *
     * Proof of payment does not confirm a booking; a member of staff does. A
     * customer who reads "confirmed" here does not send the money, and then
     * wonders why no car is waiting. The confirmation page refuses the word for
     * the same reason, and this asserts the email agrees with it.
     */
    public function test_the_submitted_email_renders_and_never_claims_confirmation(): void
    {
        $booking = $this->booking(['email' => 'joseph@example.com']);

        $rendered = (new BookingSubmittedMail(
            booking: $booking,
            amountToPayNow: '1110.00',
            instructions: 'Transfer to Demo Bank, account 0000000000.',
            deadlineAt: CarbonImmutable::parse('2026-09-18T10:00:00Z'),
            isShortNotice: false,
        ))->render();

        $this->assertStringContainsString($booking->reference, $rendered);
        $this->assertStringContainsString('1,110.00', $rendered);
        $this->assertStringContainsString('Demo Bank', $rendered);
        $this->assertStringContainsString('not confirmed yet', $rendered);
    }

    /**
     * Spec §8.2: inside the short-notice window nothing is held. A customer who
     * reads "your vehicle is held for you" and drives to a branch that has none
     * was misled by us, not by the rule.
     */
    public function test_a_short_notice_booking_says_no_vehicle_is_held(): void
    {
        $booking = $this->booking(['email' => 'joseph@example.com']);

        $rendered = (new BookingSubmittedMail(
            booking: $booking,
            amountToPayNow: '1110.00',
            instructions: '',
            deadlineAt: null,
            isShortNotice: true,
        ))->render();

        $this->assertStringContainsString('no vehicle is being held', $rendered);
        $this->assertStringNotContainsString('held for you until the deadline', $rendered);
    }

    public function test_a_part_payment_email_states_what_is_still_owed(): void
    {
        $booking = $this->booking(['email' => 'joseph@example.com']);

        $rendered = (new PaymentConfirmedMail(
            booking: $booking,
            amountConfirmed: '1110.00',
            balanceDue: '1110.00',
            isFullySettled: false,
        ))->render();

        $this->assertStringContainsString('Still to pay', $rendered);
        $this->assertStringContainsString('1,110.00', $rendered);
        // Not confirmed while money is outstanding.
        $this->assertStringNotContainsString('Your booking is confirmed', $rendered);
    }

    public function test_a_fully_settled_payment_email_confirms_the_booking(): void
    {
        $booking = $this->booking(['email' => 'joseph@example.com']);

        $rendered = (new PaymentConfirmedMail(
            booking: $booking,
            amountConfirmed: '2220.00',
            balanceDue: '0.00',
            isFullySettled: true,
        ))->render();

        $this->assertStringContainsString('Your booking is confirmed', $rendered);
        $this->assertStringNotContainsString('Still to pay', $rendered);
    }

    // --- Fixtures -----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $customerAttributes
     */
    private function booking(array $customerAttributes = []): Booking
    {
        $customer = Customer::factory()->create($customerAttributes);

        return Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'status' => BookingStatus::PendingPayment,
            'pickup_at' => CarbonImmutable::parse('2026-09-20T07:00:00Z'),
            'dropoff_at' => CarbonImmutable::parse('2026-09-23T07:00:00Z'),
            'payment_deadline_at' => CarbonImmutable::parse('2026-09-18T10:00:00Z'),
        ]);
    }

    private function confirmationResult(Booking $booking, string $balanceDue): PaymentConfirmationResult
    {
        // `amount` is what arrived, `expected_amount` what was asked for.
        // There is no `amount_received` column, and assuming one is what the
        // first version of this test — and of the notifier — got wrong.
        $payment = Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'expected_amount' => '1110.00',
            'amount' => '1110.00',
            'status' => PaymentStatus::Confirmed,
        ]);

        return new PaymentConfirmationResult(
            payment: $payment,
            confirmation: PaymentConfirmation::factory()->create([
                'payment_id' => $payment->getKey(),
            ]),
            booking: $booking,
            amountPaid: '1110.00',
            balanceDue: $balanceDue,
            paymentStatus: Money::isZero($balanceDue)
                ? BookingPaymentStatus::PaidInFull
                : BookingPaymentStatus::PartiallyPaid,
            bookingStatusBefore: BookingStatus::PendingPayment,
            bookingStatusAfter: BookingStatus::Confirmed,
            hasShortfall: false,
            shortfallAmount: Money::ZERO,
            isOverpaid: false,
            overpaidAmount: Money::ZERO,
        );
    }
}
