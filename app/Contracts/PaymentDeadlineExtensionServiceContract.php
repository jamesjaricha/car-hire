<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\DeadlineNotExtendableException;
use App\Exceptions\StaffPermissionDeniedException;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Gives a customer longer to pay. Spec §8.2, §12 `payments.extend-deadline`.
 *
 * The override path, not the mechanism. The guideline is firm that the
 * automatic rule "must work unattended at 21:00 on a Sunday" — this exists for
 * the manager who has spoken to the customer and knows the transfer is coming.
 *
 * It is a service rather than a column update because a deadline lives in two
 * places: `bookings.payment_deadline_at` and the `expires_at` of the hold
 * backing it. Moving one without the other gives the customer more time while
 * their car goes back on sale.
 */
interface PaymentDeadlineExtensionServiceContract
{
    /**
     * @throws StaffPermissionDeniedException when §12 does not grant this
     *                                        person `payments.extend-deadline`.
     * @throws DeadlineNotExtendableException when the booking is not awaiting
     *                                        payment, never had a deadline, or
     *                                        the new one is incoherent.
     */
    public function extend(
        User $actor,
        Booking $booking,
        CarbonImmutable $newDeadline,
        ?string $reason = null,
    ): Booking;
}
