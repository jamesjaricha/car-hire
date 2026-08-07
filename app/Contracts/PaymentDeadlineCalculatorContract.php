<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\PaymentWindow;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Works out by when a booking must be paid for.
 *
 * Spec §8.2:
 *
 *     deadline = min(method hold duration, pickup − 2 hours)
 *
 * and, if pickup is less than four hours away, there is no online payment and
 * no hold at all.
 *
 * The guideline is emphatic that this must work unattended: "the automatic rule
 * must work unattended at 21:00 on a Sunday". Staff overrides exist, but they
 * are an exception path, not the mechanism.
 */
interface PaymentDeadlineCalculatorContract
{
    public function calculate(
        PaymentMethod $method,
        CarbonImmutable $pickupAt,
        ?CarbonImmutable $now = null,
    ): PaymentWindow;

    /**
     * When to nudge a customer whose window runs from `$from` to `$deadline`.
     *
     * Public because a staff member extending a deadline needs the reminder
     * recalculated by the same rule that set it in the first place. Left
     * private, the extension path would have grown its own copy, and the two
     * would have drifted the first time the percentage setting changed.
     *
     * Null when no useful reminder exists — the configured percentage is 0 or
     * 100, or the window is too short for the reminder to land before the
     * deadline it is reminding about.
     */
    public function reminderFor(CarbonImmutable $from, CarbonImmutable $deadline): ?CarbonImmutable;
}
