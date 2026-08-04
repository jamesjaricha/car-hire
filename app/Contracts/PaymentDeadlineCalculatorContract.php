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
}
