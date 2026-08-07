<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Booking expiry
|--------------------------------------------------------------------------
|
| Spec §8.4: an unpaid booking is cancelled when its deadline passes, its
| payment expires and its hold is released. Spec §8.2 adds the requirement
| this schedule exists to meet — "the automatic rule must work unattended at
| 21:00 on a Sunday".
|
| Every five minutes rather than hourly. A deadline is a promise to the
| customer and a claim on a vehicle: an hourly sweep would keep a car off sale
| for up to an hour after the claim on it lapsed, which on a small fleet is a
| booking lost for no reason.
|
| withoutOverlapping() because the run holds row locks and a slow one must not
| have a second copy queueing behind it. onOneServer() is harmless on the
| single 20i host and correct if that ever changes.
|
| runInBackground() is deliberately NOT used: the sweep is short, and running
| it inline means a failure shows up in the scheduler's own exit status where
| monitoring can see it. The guideline's warning is that this job dying is
| silent, so it is wired to be as loud as it can be.
|
*/

Schedule::command('carhire:expire-bookings')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
