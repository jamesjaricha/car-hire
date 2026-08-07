<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\BookingExpiryServiceContract;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Cancels bookings whose payment deadline has passed. Spec §8.4.
 *
 * Runs on a schedule, and is meant to be run by hand as well. The guideline is
 * blunt about why: "If the scheduled job dies, vehicles stay locked and
 * inventory silently disappears. Add monitoring and a manual 'release expired
 * holds' admin action."
 *
 * Unlike the other console commands in this project, this one is NOT a test
 * harness and does run in production. That is the point of it.
 */
final class ExpireBookingsCommand extends Command
{
    protected $signature = 'carhire:expire-bookings';

    protected $description = 'Cancel bookings whose payment deadline has passed, and release lapsed holds.';

    public function handle(BookingExpiryServiceContract $expiry): int
    {
        $result = $expiry->sweep();

        $this->info($result->summary());

        // Surfaced every run rather than only when it changes, because these
        // are bookings holding customers' money that no automatic process will
        // ever deal with. A number that appears in the log each night is harder
        // to forget than a queue nobody has built a screen for yet.
        $stalled = Booking::query()->stalledAfterDeadline()->count();

        if ($stalled > 0) {
            $this->warn(sprintf(
                '%d part-paid booking(s) are past their deadline and need a decision from staff. '
                .'Each one is holding money the customer has paid.',
                $stalled,
            ));
        }

        return self::SUCCESS;
    }
}
