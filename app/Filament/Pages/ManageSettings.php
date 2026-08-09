<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Contracts\SettingsRepositoryContract;
use App\Enums\SettingKey;
use App\Enums\StaffPermission;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The operator's control panel: spec §15's answers, and the values §15 already
 * settled.
 *
 * WHY THIS IS A PAGE AND NOT A RESOURCE
 *
 * A resource over the `settings` table would be row-per-setting CRUD, and every
 * value would be a free-text string. `booking_deposit_percentage` decides what
 * every customer is asked to pay; `admin_fee_amount` is subtracted from real
 * refunds. A screen that lets somebody type "banana" into either, and a booking
 * engine that then reads it, is not a screen worth having.
 *
 * So each key gets the control its type deserves — a bounded number for the
 * deposit percentage, a money field for the fee, a toggle for foreign licences,
 * a select for the fuel policy — and the settings table stays a store rather
 * than a user interface.
 *
 * THE PLACEHOLDER RULE, WHICH IS THE SUBTLE PART
 *
 * `SettingsRepository::set()` takes `$isPlaceholder` and defaults it to false,
 * so calling it clears the flag. A naive "loop every key and save it" would
 * therefore mark all seventeen settings as decided the first time anybody
 * pressed Save — including the twelve nobody had looked at.
 *
 * That would be worse than leaving them flagged, because the flags are what
 * OPEN-ITEMS.md and every warning in the panel read from. Silencing them all in
 * one click removes the only mechanism that says which figures are still
 * guesses.
 *
 * So `save()` compares each submitted value against what is stored and calls
 * `set()` only for the ones that actually changed. If a human typed it, the
 * business has decided it; if they did not touch it, nothing about it has
 * changed, including whether it was ever decided. There is a test for exactly
 * this, and it is the most important test on this screen.
 */
final class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Settings';

    /**
     * Form state. Bound by `statePath('data')` below.
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasPermissionTo(StaffPermission::SettingsManage);
    }

    /**
     * How many §15 answers are still outstanding, on the navigation item.
     */
    public static function getNavigationBadge(): ?string
    {
        $outstanding = Setting::query()->where('is_placeholder', true)->count();

        return $outstanding === 0 ? null : (string) $outstanding;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->getSchema('content')?->fill($this->currentValues());
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->outstandingDecisionsWarning(),

                Section::make('Booking engine')
                    ->description('Values the specification settles. Changing one takes effect on the next quote; bookings already taken keep the figures they were sold at.')
                    ->columns(2)
                    ->schema($this->fieldsFor('booking')),

                Section::make('Charges')
                    ->description('Deducted from real money. Spec §15.1 requires the admin fee to be published in the T&Cs and shown before payment.')
                    ->columns(2)
                    ->schema($this->fieldsFor('charges')),

                Section::make('Hire policy')
                    ->columns(2)
                    ->schema($this->fieldsFor('policy')),

                Section::make('Driver requirements')
                    ->columns(2)
                    ->schema($this->fieldsFor('kyc')),

                Section::make('Notifications')
                    ->description('Zambian SMS requires a registered sender ID and carries a per-message cost.')
                    ->columns(2)
                    ->schema($this->fieldsFor('notifications')),

                Actions::make([
                    Action::make('save')
                        ->label('Save settings')
                        ->submit('save')
                        ->action(fn () => $this->save()),
                ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        /** @var array<string, mixed> $state */
        $state = $this->getSchema('content')?->getState() ?? [];

        $settings = app(SettingsRepositoryContract::class);

        // Read raw, not through the repository: the comparison has to be
        // against what is actually stored, in the form it is stored in.
        $stored = Setting::query()->get(['key', 'value', 'type'])->keyBy('key');

        $changed = [];

        foreach (SettingKey::cases() as $key) {
            if (! array_key_exists($key->value, $state)) {
                continue;
            }

            $row = $stored->get($key->value);
            $type = $row?->type ?? 'string';

            // BOTH sides go through the same normaliser. Comparing raw strings
            // reported a change on every save for the two money settings,
            // because a field filled with '0.00' comes back as '0' — and a
            // spurious change clears a placeholder flag, which is the one thing
            // this screen must never do by accident.
            $incoming = $this->normalise($state[$key->value], $type);

            if ($incoming === $this->normalise($row?->value, $type)) {
                continue;
            }

            // Only here. A value nobody touched keeps its placeholder flag,
            // because nobody has decided anything about it — see the class
            // docblock.
            $settings->set($key, $incoming);

            $changed[] = $key->value;
        }

        if ($changed === []) {
            Notification::make()
                ->title('Nothing changed')
                ->body('No settings were different from what is already stored.')
                ->info()
                ->send();

            return;
        }

        Notification::make()
            ->title(count($changed).' '.(count($changed) === 1 ? 'setting' : 'settings').' saved')
            ->body('Anything you edited is now recorded as a decision rather than a placeholder.')
            ->success()
            ->send();
    }

    /**
     * The banner. Only rendered while something is still undecided.
     */
    private function outstandingDecisionsWarning(): Component
    {
        return TextEntry::make('outstanding_decisions')
            ->hiddenLabel()
            ->color('warning')
            ->weight('bold')
            ->visible(fn (): bool => Setting::query()->where('is_placeholder', true)->exists())
            ->state(function (): string {
                $names = Setting::query()
                    ->where('is_placeholder', true)
                    ->orderBy('group')
                    ->pluck('label')
                    ->all();

                return sprintf(
                    '%d %s still holding a placeholder rather than a decision: %s. '
                    .'Every one of these is in use — the platform reads them exactly as though they '
                    .'had been chosen. Editing a field below records it as decided.',
                    count($names),
                    count($names) === 1 ? 'setting is' : 'settings are',
                    implode(', ', $names),
                );
            });
    }

    /**
     * The controls for one settings group, in the order the enum declares them.
     *
     * @return list<Component>
     */
    private function fieldsFor(string $group): array
    {
        $fields = [];

        foreach (SettingKey::cases() as $key) {
            if ($key->group() !== $group) {
                continue;
            }

            $fields[] = $this->fieldFor($key);
        }

        return $fields;
    }

    /**
     * The right control for one key.
     *
     * A match rather than a lookup on the stored `type` column, because the
     * constraints are per-key rather than per-type: a percentage is bounded at
     * 100, a policy is one of a fixed set, and a sender ID has a length limit
     * the network imposes.
     */
    private function fieldFor(SettingKey $key): Component
    {
        $stored = Setting::query()->where('key', $key->value)->first();

        $label = $stored?->label ?? $key->value;
        $helper = $stored?->description;

        $field = match ($key) {
            SettingKey::BookingDepositPercentage,
            SettingKey::HoldReminderRemainingPercentage => TextInput::make($key->value)
                ->numeric()
                ->minValue(1)
                ->maxValue(100)
                ->suffix('%')
                ->required(),

            SettingKey::ShortNoticeThresholdHours,
            SettingKey::DeadlinePickupMarginHours,
            SettingKey::CancellationNoticeHours => TextInput::make($key->value)
                ->numeric()
                ->minValue(0)
                ->suffix('hours')
                ->required(),

            SettingKey::BasketTtlMinutes,
            SettingKey::DefaultTurnaroundBufferMinutes => TextInput::make($key->value)
                ->numeric()
                ->minValue(1)
                ->suffix('minutes')
                ->required(),

            SettingKey::AdminFeeAmount,
            SettingKey::LateReturnHourlyCharge => TextInput::make($key->value)
                ->numeric()
                ->minValue(0)
                ->prefix('ZMW')
                ->required(),

            SettingKey::MinimumDriverAge => TextInput::make($key->value)
                ->numeric()
                ->minValue(16)
                ->maxValue(99)
                ->suffix('years old')
                ->required(),

            SettingKey::MinimumLicenceYears => TextInput::make($key->value)
                ->numeric()
                ->minValue(0)
                ->maxValue(50)
                ->suffix('years held')
                ->required(),

            SettingKey::ForeignLicenceAccepted,
            SettingKey::CounterClerkMayConfirmCash => Toggle::make($key->value),

            SettingKey::FuelPolicy => Select::make($key->value)
                ->options([
                    'full_to_full' => 'Full to full',
                    'charged_shortfall' => 'Charged shortfall',
                ])
                ->required(),

            SettingKey::MileagePolicy => Select::make($key->value)
                ->options([
                    'unlimited' => 'Unlimited',
                    'daily_cap' => 'Daily cap with an excess rate',
                ])
                ->required(),

            SettingKey::SmsProvider,
            SettingKey::SmsSenderId => TextInput::make($key->value)
                ->maxLength(64),
        };

        return $field
            ->label($label)
            ->helperText($helper)
            // The §15 warning, on the field it applies to rather than only in
            // the banner — somebody editing one setting should not have to
            // scroll back up to learn that the figure beside it is a guess.
            ->hint(fn (): ?string => ($stored?->is_placeholder ?? false) ? 'PLACEHOLDER' : null)
            ->hintColor('warning');
    }

    /**
     * Every setting's current value, typed, ready to fill the form.
     *
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $settings = app(SettingsRepositoryContract::class);

        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values[$key->value] = $settings->get($key);
        }

        return $values;
    }

    /**
     * One canonical string for a value of a given setting type.
     *
     * Applied to the submitted value AND to the stored one before they are
     * compared, and the normalised form is what gets written. Without it, "has
     * this changed" answers yes for values that are equal but differently
     * shaped — '0' against '0.00' being the case that actually bit — and every
     * spurious yes silently marks a §15 placeholder as decided.
     *
     * Money goes through `Money` rather than `number_format`, for the reason
     * every other monetary comparison in this codebase does: one implementation,
     * bcmath, no floats.
     */
    private function normalise(mixed $value, string $type): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = $value === null ? '' : (string) $value;

        return match ($type) {
            'decimal' => $value === '' ? '' : Money::of($value),
            'integer' => $value === '' ? '' : (string) (int) $value,
            'boolean' => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0',
            default => $value,
        };
    }
}
