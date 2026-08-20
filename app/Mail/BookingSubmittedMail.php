<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We have your booking. Here is how to pay for it." Spec §13.
 *
 * WHAT THIS EMAIL MUST NOT SAY
 *
 * It must not say the booking is confirmed. Spec §7.3: proof of payment never
 * confirms a booking on its own, and a customer who reads "confirmed" here does
 * not send the money. The confirmation page refuses the word for the same
 * reason and this email agrees with it.
 *
 * WHY IT IS QUEUED
 *
 * `ShouldQueue` keeps SMTP out of the request. A customer pressing "Reserve"
 * must not wait on a mail server, and must certainly not see a 500 because one
 * is down — the booking already exists at that point.
 *
 * ⚠ There is no daemonised queue worker on 20i shared hosting. The scheduler
 * drains the queue every minute instead — see `routes/console.php`. Without
 * that entry these emails queue up in the `jobs` table and are never sent,
 * which looks exactly like mail being broken.
 */
final class BookingSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $amountToPayNow,
        public readonly string $instructions,
        public readonly ?CarbonImmutable $deadlineAt,
        public readonly bool $isShortNotice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The reference in the subject line so a customer can find this
            // again by searching their inbox, and quote it on the telephone
            // without opening the message.
            subject: 'Your booking '.$this->booking->reference.' — how to pay',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking-submitted',
            with: [
                'booking' => $this->booking,
                'amountToPayNow' => $this->amountToPayNow,
                'instructions' => $this->instructions,
                'deadlineAt' => $this->deadlineAt,
                'isShortNotice' => $this->isShortNotice,
                'zone' => (string) config('carhire.display_timezone', 'Africa/Lusaka'),
                'currency' => (string) config('carhire.currency', 'ZMW'),
            ],
        );
    }
}
