<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "A member of staff has checked your payment." Spec §13.
 *
 * WHY IT IS SENT FOR PART PAYMENTS TOO
 *
 * A customer paying the 50% booking deposit has done what was asked and needs
 * to know the money arrived — and, just as importantly, what is still owed and
 * when. Sending only on full settlement would leave the most common case in the
 * whole flow silent.
 *
 * So the subject and body change with the outcome: a fully settled booking is
 * confirmed, a part-paid one says what remains. `$isFullySettled` is computed
 * by the notifier from the ledger, not guessed at here.
 */
final class PaymentConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $amountConfirmed,
        public readonly string $balanceDue,
        public readonly bool $isFullySettled,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isFullySettled
                ? 'Booking '.$this->booking->reference.' is confirmed'
                : 'Payment received for booking '.$this->booking->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payment-confirmed',
            with: [
                'booking' => $this->booking,
                'amountConfirmed' => $this->amountConfirmed,
                'balanceDue' => $this->balanceDue,
                'isFullySettled' => $this->isFullySettled,
                'zone' => (string) config('carhire.display_timezone', 'Africa/Lusaka'),
                'currency' => (string) config('carhire.currency', 'ZMW'),
            ],
        );
    }
}
