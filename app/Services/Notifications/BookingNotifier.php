<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\BookingNotifierContract;
use App\Contracts\PaymentAdapterResolverContract;
use App\DataTransferObjects\BookingCreationResult;
use App\DataTransferObjects\PaymentConfirmationResult;
use App\Mail\BookingSubmittedMail;
use App\Mail\PaymentConfirmedMail;
use App\Models\Booking;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Spec §13, email only. SMS is not built.
 *
 * ⚠ EVERY METHOD SWALLOWS ITS OWN FAILURES, AND THAT IS DELIBERATE
 *
 * This is the one place on this project where an exception is caught and
 * logged rather than allowed to travel. Everywhere else a fault is made loud,
 * because a silent wrong answer about money is worse than a crash.
 *
 * Notification is the opposite case. The booking has already been created and
 * committed by the time this runs. If SMTP is unreachable, throwing would turn
 * a working booking into a 500 at the exact moment the customer is deciding
 * whether to trust this operator with a bank transfer — and leave them unsure
 * whether they have a booking at all. An email that did not arrive is a problem
 * the operator can fix; a customer who walked away is not.
 *
 * The consequence to know about: **when an email does not arrive, nothing on
 * screen says so.** `storage/logs/laravel.log` is the only place it is
 * recorded. That is written in DEPLOYMENT.md.
 */
final class BookingNotifier implements BookingNotifierContract
{
    public function __construct(
        private readonly PaymentAdapterResolverContract $adapters,
    ) {}

    public function bookingSubmitted(BookingCreationResult $result): void
    {
        $booking = $result->booking;
        $email = $this->emailFor($booking);

        if ($email === null) {
            return;
        }

        try {
            $payment = $result->payment;
            $method = $payment->paymentMethod;

            // The same adapter that draws the confirmation page. Two copies of
            // "how to pay" would agree until one of them was edited, and the
            // one in an email is the copy the customer keeps.
            $instructions = $method === null
                ? ''
                : $this->adapters->for($method->code)->instructionsFor(
                    $payment,
                    $method,
                    $booking->payment_deadline_at,
                );

            Mail::to($email)->queue(new BookingSubmittedMail(
                booking: $booking,
                amountToPayNow: Money::of((string) $payment->expected_amount),
                instructions: $instructions,
                deadlineAt: $booking->payment_deadline_at,
                // Spec §8.2: inside the short-notice window no hold is placed,
                // and the email must say so. A customer who reads "we are
                // holding your vehicle" and drives to a branch that has none
                // was misled by us, not by the rule.
                isShortNotice: $result->hold === null,
            ));
        } catch (Throwable $e) {
            $this->reportFailure('booking submitted', $booking, $e);
        }
    }

    public function paymentConfirmed(PaymentConfirmationResult $result): void
    {
        $booking = $result->booking;
        $email = $this->emailFor($booking);

        if ($email === null) {
            return;
        }

        try {
            Mail::to($email)->queue(new PaymentConfirmedMail(
                booking: $booking,
                // `amount` is what ARRIVED; `expected_amount` is what was asked
                // for. There is no `amount_received` — an earlier draft read
                // that, and outside production `shouldBeStrict()` throws on a
                // missing attribute, while IN production it would have returned
                // null and told the customer their payment of ZMW 0.00 had been
                // received. Caught by a test, which is the only reason it is
                // not in the operator's inbox.
                amountConfirmed: Money::of((string) $result->payment->amount),
                balanceDue: Money::of($result->balanceDue),
                // Asked of the ledger's own figure rather than inferred from
                // the booking's status, which moves for reasons other than
                // money.
                isFullySettled: Money::isZero($result->balanceDue),
            ));
        } catch (Throwable $e) {
            $this->reportFailure('payment confirmed', $booking, $e);
        }
    }

    /**
     * The address to write to, or null when there is nobody to write to.
     *
     * A booking taken at the counter may have no email at all — spec §1.3 asks
     * for one at online checkout, and staff recording a walk-in have whatever
     * the customer gave them. That is not a fault and must not be logged as
     * one, or the log fills with noise and stops being read.
     */
    private function emailFor(Booking $booking): ?string
    {
        $email = $booking->customer?->email;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return $email;
    }

    private function reportFailure(string $what, Booking $booking, Throwable $e): void
    {
        Log::error('Could not send the "'.$what.'" email.', [
            'booking_reference' => $booking->reference,
            'exception' => $e->getMessage(),
        ]);
    }
}
