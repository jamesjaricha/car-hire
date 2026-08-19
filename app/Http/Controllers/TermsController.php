<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\PaymentMethodServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Enums\SettingKey;
use App\Models\Branch;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * The terms of hire, assembled from what the platform actually charges.
 *
 * WHY THIS PAGE IS GENERATED RATHER THAN WRITTEN
 *
 * Spec §6 requires the security deposit to appear in the terms and conditions,
 * and §10 requires the insurance excess to be stated. Until now there were no
 * terms at all — a plain spec gap rather than a matter of polish.
 *
 * Written prose would have been quicker and would have started drifting the
 * first time somebody edited a figure in the panel. Every number here is read
 * from the same source the customer is charged from: the deposit percentage
 * from `settings`, the security deposit and excess from each `vehicle_class`,
 * the cancellation window and admin fee from `settings`, the payment methods
 * from the service that decides what checkout offers. **The page cannot
 * contradict the checkout, because it is reading the checkout's own data.**
 *
 * ⚠ AN UNDECIDED FIGURE IS NEVER PRINTED AS A TERM
 *
 * This is the important rule, and it is stronger here than anywhere else in the
 * platform. Elsewhere a §15 placeholder produces a warning in the panel or
 * withholds a class from sale. Here it would produce a CONTRACTUAL STATEMENT
 * the operator never made — "an administration fee of ZMW 0.00 applies" is a
 * promise, published, to every customer who reads it.
 *
 * So every policy value passes through `decided()`, which returns null when
 * `SettingsRepository::isPlaceholder()` says nobody has chosen it. The view
 * then says the term will be confirmed rather than inventing one. See
 * `docs/OPEN-ITEMS.md` and [[feedback_undecided_is_not_zero]] — the same
 * lesson, at its highest stakes.
 *
 * A decided ZERO is different from an undecided one and prints normally. That
 * distinction is exactly why those columns are nullable.
 */
final class TermsController extends Controller
{
    /**
     * Far enough ahead that the §8.2 short-notice rule excludes nothing, so the
     * page describes what is NORMALLY available rather than what happens to be
     * offerable for a pickup in the next few hours.
     */
    private const NORMAL_NOTICE_DAYS = 7;

    public function __construct(
        private readonly SettingsRepositoryContract $settings,
        private readonly PaymentMethodServiceContract $paymentMethods,
    ) {}

    public function __invoke(): View
    {
        return view('terms', [
            'policy' => $this->policy(),

            // Only classes that can actually be sold. An unpriced class is
            // withheld from search, so publishing its terms would describe a
            // hire nobody can book — and its figures are the undecided ones.
            'classes' => VehicleClass::query()
                ->active()
                ->fullyPriced()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),

            'branches' => Branch::query()
                ->active()
                ->orderBy('city')
                ->orderBy('name')
                ->get(),

            'paymentMethods' => $this->paymentMethods->selectableFor(
                CarbonImmutable::now()->addDays(self::NORMAL_NOTICE_DAYS),
            ),

            'currency' => (string) config('carhire.currency', 'ZMW'),
            'updatedAt' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Every policy figure, or null where nobody has decided it.
     *
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        return [
            'deposit_percentage' => $this->decided(
                SettingKey::BookingDepositPercentage,
                fn (): ?int => $this->settings->integer(SettingKey::BookingDepositPercentage),
            ),
            'cancellation_notice_hours' => $this->decided(
                SettingKey::CancellationNoticeHours,
                fn (): ?int => $this->settings->integer(SettingKey::CancellationNoticeHours),
            ),
            'admin_fee' => $this->decided(
                SettingKey::AdminFeeAmount,
                fn (): ?string => $this->settings->decimal(SettingKey::AdminFeeAmount),
            ),
            'fuel_policy' => $this->decided(
                SettingKey::FuelPolicy,
                fn (): ?string => $this->settings->string(SettingKey::FuelPolicy),
            ),
            'mileage_policy' => $this->decided(
                SettingKey::MileagePolicy,
                fn (): ?string => $this->settings->string(SettingKey::MileagePolicy),
            ),
            'late_return_charge' => $this->decided(
                SettingKey::LateReturnHourlyCharge,
                fn (): ?string => $this->settings->decimal(SettingKey::LateReturnHourlyCharge),
            ),
            'minimum_driver_age' => $this->decided(
                SettingKey::MinimumDriverAge,
                fn (): ?int => $this->settings->integer(SettingKey::MinimumDriverAge),
            ),
            'minimum_licence_years' => $this->decided(
                SettingKey::MinimumLicenceYears,
                fn (): ?int => $this->settings->integer(SettingKey::MinimumLicenceYears),
            ),
            'foreign_licence_accepted' => $this->decided(
                SettingKey::ForeignLicenceAccepted,
                fn (): bool => $this->settings->boolean(SettingKey::ForeignLicenceAccepted),
            ),

            // Not §15 open items — these are settled by the specification
            // itself (§8.2), so they are always printed.
            'short_notice_hours' => $this->settings->integer(SettingKey::ShortNoticeThresholdHours),
            'deadline_margin_hours' => $this->settings->integer(SettingKey::DeadlinePickupMarginHours),
        ];
    }

    /**
     * The value, or NULL when it is still a seeded placeholder.
     *
     * The callback is only invoked for a decided setting, so a placeholder can
     * never leak through by accident — there is no path where the figure is
     * read and then discarded, which is the shape a later refactor tends to
     * flatten into "just print it".
     */
    private function decided(SettingKey $key, callable $value): mixed
    {
        return $this->settings->isPlaceholder($key) ? null : $value();
    }
}
