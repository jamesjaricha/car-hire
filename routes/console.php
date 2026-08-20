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

/*
|--------------------------------------------------------------------------
| Draining the mail queue
|--------------------------------------------------------------------------
|
| ⚠ WITHOUT THIS ENTRY, NO EMAIL IS EVER SENT.
|
| The §13 notifications are queued Mailables, so SMTP never blocks a customer
| pressing "Reserve". But there is no daemonised queue worker on 20i shared
| hosting — DEPLOYMENT.md is explicit about it — so a queued job sits in the
| `jobs` table until something runs it. This is that something.
|
| The failure mode if it is removed is the nastiest kind: bookings succeed,
| nothing errors, jobs accumulate silently, and the operator concludes the mail
| server is broken. `SELECT COUNT(*) FROM jobs` is the check.
|
| --stop-when-empty so the process exits rather than becoming a daemon the
| scheduler cannot manage. --max-time=50 keeps it inside the minute it was
| started in, so consecutive runs cannot pile up. --tries=3 because a mail
| server that refuses once often accepts a minute later; after three it goes to
| `failed_jobs`, where it can be found rather than lost.
|
| runInBackground() so a slow mail server cannot delay the expiry sweep, which
| is the schedule that actually affects what customers can book.
|
*/

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
