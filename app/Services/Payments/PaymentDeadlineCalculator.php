<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentDeadlineCalculatorContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\PaymentWindow;
use App\Enums\SettingKey;
use App\Models\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Spec §8.2, implemented.
 *
 * Every threshold here is a setting rather than a constant, so the operator can
 * change how long a bank transfer holds a vehicle without a deploy.
 */
final class PaymentDeadlineCalculator implements PaymentDeadlineCalculatorContract
{
    public function __construct(
        private readonly SettingsRepositoryContract $settings,
    ) {}

    public function calculate(
        PaymentMethod $method,
        CarbonImmutable $pickupAt,
        ?CarbonImmutable $now = null,
    ): PaymentWindow {
        $now ??= CarbonImmutable::now();

        // Compared as timestamps rather than through diffInHours, whose sign
        // and return type have changed between Carbon major versions. This is
        // unambiguous and cannot drift.
        $hoursToPickup = ($pickupAt->getTimestamp() - $now->getTimestamp()) / 3600;

        // Pickup imminent: no remote payment can be sent and verified in time,
        // so the customer pays at the counter and nothing is held. A pickup
        // already in the past also lands here — validating that is the booking
        // service's job, but this must not invent a deadline for it either way.
        if ($hoursToPickup < $this->shortNoticeThresholdHours()) {
            return PaymentWindow::payAtBranch();
        }

        $latestUseful = $pickupAt->subHours($this->pickupMarginHours());
        $methodAllows = $now->addHours($method->hold_duration_hours);

        // Whichever comes first. A 48-hour bank transfer window is irrelevant
        // if the customer collects the car in twelve hours.
        $deadline = $methodAllows->lessThan($latestUseful) ? $methodAllows : $latestUseful;

        // Defensive: a deadline at or before now would expire the booking the
        // instant it was created. The short-notice branch above should already
        // have caught this, so reaching here means the thresholds have been
        // configured inconsistently.
        if ($deadline->lessThanOrEqualTo($now)) {
            return PaymentWindow::payAtBranch();
        }

        return new PaymentWindow(
            deadlineAt: $deadline,
            placesHold: true,
            isShortNotice: false,
            reminderAt: $this->reminderFor($now, $deadline),
        );
    }

    /**
     * When to nudge the customer, once the configured share of their window
     * remains. Spec §8.4 puts the default at 25%.
     *
     * Public so that extending a deadline recalculates the reminder by this
     * same rule rather than growing a second copy of it.
     */
    public function reminderFor(CarbonImmutable $now, CarbonImmutable $deadline): ?CarbonImmutable
    {
        $remainingPercentage = $this->settings->integer(
            SettingKey::HoldReminderRemainingPercentage,
            25,
        ) ?? 25;

        if ($remainingPercentage <= 0 || $remainingPercentage >= 100) {
            return null;
        }

        $windowSeconds = $deadline->getTimestamp() - $now->getTimestamp();
        $elapsedBeforeReminder = (int) round($windowSeconds * (100 - $remainingPercentage) / 100);

        $reminder = $now->addSeconds($elapsedBeforeReminder);

        // A reminder that would fire at or after the deadline is no reminder.
        return $reminder->lessThan($deadline) ? $reminder : null;
    }

    private function shortNoticeThresholdHours(): int
    {
        return $this->settings->integer(SettingKey::ShortNoticeThresholdHours, 4) ?? 4;
    }

    private function pickupMarginHours(): int
    {
        return $this->settings->integer(SettingKey::DeadlinePickupMarginHours, 2) ?? 2;
    }
}
