<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\BookingCreationResult;
use App\DataTransferObjects\PaymentConfirmationResult;

/**
 * Telling a customer what has happened to their booking. Spec §13.
 *
 * WHY THIS IS AN INTERFACE AND NOT A `Mail::send()` IN THE SERVICE
 *
 * `BookingCreationService::create()` and `PaymentConfirmationService::confirm()`
 * exist to be correct about money and vehicles. Neither should know what a
 * mail transport is, and neither should be untestable without one.
 *
 * More practically: a notifier bound to an interface can be swapped for a fake
 * in the suites that already exercise those services, so nothing that tests a
 * booking has to think about email at all.
 *
 * ⚠ NOTHING HERE MAY THROW.
 *
 * A customer's booking must not fail because a mail server is down. An
 * unreachable SMTP host is an operational problem for the operator; a 500 at
 * the moment somebody presses "Reserve" is a lost customer and, worse, a
 * booking that may or may not exist depending on where it failed.
 *
 * Implementations swallow and log. That is a deliberate inversion of the usual
 * rule on this project — everywhere else a failure is made loud — and it is
 * why `storage/logs` is the place to look when an email does not arrive.
 */
interface BookingNotifierContract
{
    /**
     * Spec §13: booking submitted, with payment instructions.
     *
     * Sent after the creating transaction has COMMITTED. `create()` runs with
     * `attempts: 3`, so anything dispatched inside it could go out three times
     * for one booking.
     */
    public function bookingSubmitted(BookingCreationResult $result): void;

    /**
     * Spec §13: payment confirmed by a member of staff.
     *
     * Sent for any confirmed receipt, including a part payment — a customer who
     * has paid a deposit needs to know it landed, and what is still owed.
     */
    public function paymentConfirmed(PaymentConfirmationResult $result): void;
}
