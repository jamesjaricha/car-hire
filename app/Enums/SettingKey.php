<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Configuration keys the operator controls.
 *
 * Spec §15 lists values that "must be answered before build starts". Rather than
 * block on them or bury guesses in constants, every one of them lives here and
 * is seeded — some with real values the spec already locks down, others with
 * clearly-flagged placeholders that the operator replaces from the admin panel.
 *
 * Nothing in this list requires a deploy to change.
 */
enum SettingKey: string
{
    // --- Booking engine (values locked by the specification) -----------------

    /** Percentage of the grand total taken as a booking deposit. Spec §5. */
    case BookingDepositPercentage = 'booking_deposit_percentage';

    /** Below this many hours to pickup, online payment is unavailable. Spec §8.2. */
    case ShortNoticeThresholdHours = 'short_notice_threshold_hours';

    /** A payment deadline never falls later than pickup minus this. Spec §8.2. */
    case DeadlinePickupMarginHours = 'deadline_pickup_margin_hours';

    /** Guest basket inactivity timeout. Spec §1.1. */
    case BasketTtlMinutes = 'basket_ttl_minutes';

    /** Reminder fires when this percentage of the hold window remains. Spec §8.4. */
    case HoldReminderRemainingPercentage = 'hold_reminder_remaining_percentage';

    /** Fallback gap between hires when a class has no buffer of its own. */
    case DefaultTurnaroundBufferMinutes = 'default_turnaround_buffer_minutes';

    // --- Open items (§15) — seeded as placeholders ---------------------------

    /** Flat fee deducted from refunds. Spec §9, §15.1. */
    case AdminFeeAmount = 'admin_fee_amount';

    /** Spec §15.9. */
    case FuelPolicy = 'fuel_policy';

    /** Spec §15.10. */
    case MileagePolicy = 'mileage_policy';

    /** Spec §15.11. */
    case LateReturnHourlyCharge = 'late_return_hourly_charge';

    /** Spec §15.5. */
    case MinimumDriverAge = 'minimum_driver_age';

    /** Spec §15.5. */
    case MinimumLicenceYears = 'minimum_licence_years';

    /** Spec §15.5. */
    case ForeignLicenceAccepted = 'foreign_licence_accepted';

    /** Spec §15.12. Default only; the per-branch override comes with roles. */
    case CounterClerkMayConfirmCash = 'counter_clerk_may_confirm_cash';

    /** Spec §15.7. */
    case SmsProvider = 'sms_provider';

    /** Spec §15.7. */
    case SmsSenderId = 'sms_sender_id';

    public function group(): string
    {
        return match ($this) {
            self::BookingDepositPercentage,
            self::ShortNoticeThresholdHours,
            self::DeadlinePickupMarginHours,
            self::BasketTtlMinutes,
            self::HoldReminderRemainingPercentage,
            self::DefaultTurnaroundBufferMinutes => 'booking',

            self::AdminFeeAmount,
            self::LateReturnHourlyCharge => 'charges',

            self::FuelPolicy,
            self::MileagePolicy => 'policy',

            self::MinimumDriverAge,
            self::MinimumLicenceYears,
            self::ForeignLicenceAccepted,
            self::CounterClerkMayConfirmCash => 'kyc',

            self::SmsProvider,
            self::SmsSenderId => 'notifications',
        };
    }
}
