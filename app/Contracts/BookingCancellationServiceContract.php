<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

/**
 * Ending a booking by hand, as opposed to the expiry sweep ending one by clock.
 */
interface BookingCancellationServiceContract
{
    /**
     * Move a booking to one of its ending states and release its vehicle.
     *
     * `$to` must be a cancellation or a no-show; anything else is refused. The
     * transition itself is still the state machine's decision, so a booking that
     * §7.3 does not allow to end this way is refused there.
     */
    public function cancel(
        User $actor,
        Booking $booking,
        BookingStatus $to,
        ?string $reason = null,
    ): Booking;
}
