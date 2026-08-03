<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds every operator-editable value.
 *
 * Two kinds of entry live here. Values the specification already locks down —
 * the 50% deposit, the four-hour short-notice threshold, the two-hour deadline
 * margin — are seeded as real settings. Values from spec §15 that only the
 * business can answer are seeded as PLACEHOLDERS and flagged as such, so they
 * surface in the admin panel and in docs/OPEN-ITEMS.md until answered.
 *
 * Uses firstOrCreate deliberately. Re-running a seeder must never overwrite a
 * real decision the operator has since made with a placeholder.
 */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            Setting::query()->firstOrCreate(
                ['key' => $definition['key']],
                [
                    'value' => $definition['value'],
                    'type' => $definition['type'],
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'is_placeholder' => $definition['is_placeholder'],
                ],
            );
        }
    }

    /**
     * @return list<array{key: string, value: ?string, type: string, group: string, label: string, description: ?string, is_placeholder: bool}>
     */
    private function definitions(): array
    {
        return [
            // --- Locked by the specification ---------------------------------
            $this->setting(
                SettingKey::BookingDepositPercentage, '50', 'integer',
                'Booking deposit percentage',
                'Share of the grand total taken to secure a booking. Spec §5 sets this at 50%.',
            ),
            $this->setting(
                SettingKey::ShortNoticeThresholdHours, '4', 'integer',
                'Short-notice threshold (hours)',
                'Inside this window before pickup, online payment is unavailable and no hold is placed. Spec §8.2.',
            ),
            $this->setting(
                SettingKey::DeadlinePickupMarginHours, '2', 'integer',
                'Deadline margin before pickup (hours)',
                'A payment deadline never falls later than pickup minus this. Spec §8.2.',
            ),
            $this->setting(
                SettingKey::BasketTtlMinutes, '30', 'integer',
                'Basket lifetime (minutes)',
                'Guest basket inactivity timeout. The quoted price holds for this long. Spec §1.1.',
            ),
            $this->setting(
                SettingKey::HoldReminderRemainingPercentage, '25', 'integer',
                'Reminder trigger (% of window remaining)',
                'Payment reminder fires when this much of the hold window is left. Spec §8.4.',
            ),
            $this->setting(
                SettingKey::DefaultTurnaroundBufferMinutes, '120', 'integer',
                'Default turnaround buffer (minutes)',
                'Fallback gap between hires for cleaning and inspection when a class sets none. Confirmed at 2 hours.',
            ),

            // --- Open items (§15) — placeholders -----------------------------
            $this->setting(
                SettingKey::AdminFeeAmount, '0.00', 'decimal',
                'Flat admin fee (ZMW)',
                'PLACEHOLDER. Deducted from refunds on cancellation and failed KYC. Must be published in the T&Cs and shown before payment. Spec §15.1.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::LateReturnHourlyCharge, '0.00', 'decimal',
                'Late return charge per hour (ZMW)',
                'PLACEHOLDER. Spec §15.11.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::FuelPolicy, 'full_to_full', 'string',
                'Fuel policy',
                'PLACEHOLDER. Full-to-full, or a charged shortfall rate. Spec §15.9.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::MileagePolicy, 'unlimited', 'string',
                'Mileage policy',
                'PLACEHOLDER. Unlimited, or a daily cap with an excess rate. Spec §15.10.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::MinimumDriverAge, '21', 'integer',
                'Minimum driver age',
                'PLACEHOLDER. Spec §15.5.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::MinimumLicenceYears, '2', 'integer',
                'Minimum years licence held',
                'PLACEHOLDER. Spec §15.5.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::ForeignLicenceAccepted, '1', 'boolean',
                'Accept foreign licences',
                'PLACEHOLDER. Policy on foreign and international driving permits. Spec §15.5.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::CounterClerkMayConfirmCash, '0', 'boolean',
                'Counter clerks may confirm cash',
                'PLACEHOLDER. Default only; spec §12 allows this to vary per branch. Spec §15.12.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::SmsProvider, '', 'string',
                'SMS provider',
                'PLACEHOLDER. Zambian SMS needs a registered sender ID and carries a per-message cost. Spec §15.7.',
                isPlaceholder: true,
            ),
            $this->setting(
                SettingKey::SmsSenderId, '', 'string',
                'SMS sender ID',
                'PLACEHOLDER. Spec §15.7.',
                isPlaceholder: true,
            ),
        ];
    }

    /**
     * @return array{key: string, value: ?string, type: string, group: string, label: string, description: ?string, is_placeholder: bool}
     */
    private function setting(
        SettingKey $key,
        ?string $value,
        string $type,
        string $label,
        ?string $description = null,
        bool $isPlaceholder = false,
    ): array {
        return [
            'key' => $key->value,
            'value' => $value,
            'type' => $type,
            'group' => $key->group(),
            'label' => $label,
            'description' => $description,
            'is_placeholder' => $isPlaceholder,
        ];
    }
}
