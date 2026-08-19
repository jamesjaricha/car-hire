<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Contracts\PaymentAdapterResolverContract;
use App\Models\PaymentMethod;
use Closure;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * How customers are told to pay, and what they are told to pay into.
 *
 * `code` and `type` are not on this form. They map to `PaymentMethodCode` cases
 * and adapter classes, and a row whose code matches no case has no adapter, no
 * instructions and no way to be confirmed — see `PaymentMethodPolicy`.
 *
 * TWO VALIDATION RULES CARRY THE WEIGHT HERE
 *
 * The account details must contain everything the method's adapter declares, or
 * the method is withheld from customers. That is enforced at checkout by
 * `PaymentMethodService`; the rule here means the operator finds out on the
 * screen where they can fix it, rather than by noticing an option missing.
 *
 * And every `:placeholder` in the instructions template must be one the adapter
 * can actually fill. An unknown one is left exactly as written — the adapter
 * refuses to guess at operator copy — so `:swift_code` typed here and never
 * supplied ships that literal text to a customer.
 */
final class PaymentMethodForm
{
    /** Merge fields every adapter provides, from `OfflinePaymentAdapter`. */
    private const UNIVERSAL_MERGE_FIELDS = ['reference', 'amount', 'method', 'deadline'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Availability')
                ->columns(2)
                ->schema([
                    TextEntry::make('code')
                        ->label('Method')
                        ->badge()
                        ->state(fn (PaymentMethod $record): string => $record->label)
                        ->helperText('The code and type are fixed: each maps to an adapter in code.'),

                    Toggle::make('enabled')
                        ->label('Accept this method')
                        ->helperText('A deployment feature flag can still force it off, and that flag wins.'),

                    TextInput::make('display_order')
                        ->label('Order shown')
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    TextInput::make('hold_duration_hours')
                        ->label('Holds a vehicle for (hours)')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->helperText('Spec §8.1: cash 24, bank transfer 48, mobile money 6. The deadline is the lesser of this and pickup minus two hours.'),

                    TextInput::make('min_lead_time_hours')
                        ->label('Minimum lead time (hours)')
                        ->numeric()
                        ->minValue(0)
                        ->nullable()
                        ->helperText('Leave empty for none. Below this many hours to pickup, the method is not offered.')
                        ->dehydrateStateUsing(fn (mixed $state): ?int => $state === null || $state === '' ? null : (int) $state),
                ]),

            Section::make('Where the money goes')
                ->description(fn (PaymentMethod $record): string => self::accountDetailsDescription($record))
                ->schema([
                    KeyValue::make('account_details')
                        ->label('Account details')
                        ->keyLabel('Field')
                        ->valueLabel('Value')
                        ->addActionLabel('Add another detail')
                        ->helperText(fn (PaymentMethod $record): string => self::requiredKeysHint($record))
                        // ⚠ THIS IS WHY THE SCREEN WAS UNUSABLE, 2026-08-19.
                        //
                        // The required keys are exact snake_case identifiers
                        // that an adapter looks up — `bank_name`,
                        // `account_number`. With an empty grid, the operator had
                        // to TYPE those keys from scratch, spelled precisely, or
                        // the save was refused naming three fields they had
                        // never been given a box for. The operator's report was
                        // that it "brought back an error which looks like the
                        // data type I had put was invalid", which is exactly
                        // what guessing an internal contract feels like.
                        //
                        // Seeding the rows turns "know our key names" into "fill
                        // in the blanks". Extra details can still be added, and
                        // still work as :merge_fields.
                        ->afterStateHydrated(static function (KeyValue $component, PaymentMethod $record): void {
                            $state = $component->getState();
                            $state = is_array($state) ? $state : [];

                            $ordered = [];

                            // Required first, in the adapter's own order, so the
                            // form reads top to bottom the way somebody copying
                            // off a bank statement expects.
                            foreach (self::requiredKeys($record) as $key) {
                                $ordered[$key] = $state[$key] ?? '';
                            }

                            foreach ($state as $key => $value) {
                                if (! array_key_exists($key, $ordered)) {
                                    $ordered[$key] = $value;
                                }
                            }

                            $component->state($ordered);
                        })
                        ->rules([
                            fn (PaymentMethod $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $missing = self::missingRequiredKeys($record, is_array($value) ? $value : []);

                                if ($missing !== []) {
                                    // Names the fields AND says they are already
                                    // on screen. The previous wording said "add
                                    // bank_name, account_name", which reads as a
                                    // demand to create something rather than an
                                    // instruction to fill a box that is right
                                    // there.
                                    $fail(sprintf(
                                        'Fill in %s above before customers can be offered this method. '
                                        .'%s still empty. Leave them blank and the method simply will not '
                                        .'appear at checkout, which is safe — a customer is never told to '
                                        .'send money to a blank account number.',
                                        count($missing) === 1 ? 'the remaining field' : 'the remaining fields',
                                        count($missing) === 1
                                            ? sprintf('"%s" is', $missing[0])
                                            : sprintf('"%s" are', implode('", "', $missing)),
                                    ));
                                }
                            },
                        ]),
                ]),

            Section::make('What the customer is told')
                ->schema([
                    Textarea::make('instructions_template')
                        ->label('Instructions')
                        ->rows(5)
                        ->nullable()
                        ->helperText(fn (PaymentMethod $record): string => 'Available merge fields: '
                            .implode(', ', array_map(
                                static fn (string $field): string => ':'.$field,
                                self::availableMergeFields($record),
                            ))
                            .'. Anything else is printed to the customer exactly as you type it.')
                        ->rules([
                            // `$get` rather than `$record`: the details being
                            // SUBMITTED are what will fill this template. Read
                            // from the saved record instead, an operator adding
                            // a field and using it in the same save is refused
                            // for a field they just supplied.
                            fn (PaymentMethod $record, Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                                $submitted = $get('account_details');

                                $unknown = self::unknownPlaceholders(
                                    $record,
                                    (string) $value,
                                    is_array($submitted) ? $submitted : null,
                                );

                                if ($unknown !== []) {
                                    $fail(sprintf(
                                        'The customer would see %s printed literally, because nothing fills %s in. '
                                        .'Add %s to the account details above, or remove %s from the wording.',
                                        implode(', ', $unknown),
                                        count($unknown) === 1 ? 'it' : 'them',
                                        count($unknown) === 1 ? 'that field' : 'those fields',
                                        count($unknown) === 1 ? 'it' : 'them',
                                    ));
                                }
                            },
                        ]),
                ]),
        ]);
    }

    /**
     * Everything `:name` this method's adapter can substitute.
     *
     * `$details` is the set being submitted where there is one, falling back to
     * what is stored. The adapter's required keys are always included: it seeds
     * each of them as an empty string when merging, so a template referencing
     * one renders blank rather than literally, even before it is filled in.
     *
     * @param  array<string, mixed>|null  $details
     * @return list<string>
     */
    private static function availableMergeFields(PaymentMethod $record, ?array $details = null): array
    {
        $keys = array_keys($details ?? $record->account_details ?? []);

        return array_values(array_unique(array_merge(
            self::UNIVERSAL_MERGE_FIELDS,
            self::requiredKeys($record),
            array_map(static fn (mixed $key): string => (string) $key, $keys),
        )));
    }

    /**
     * Placeholders in the template that nothing will fill.
     *
     * @param  array<string, mixed>|null  $details
     * @return list<string>
     */
    private static function unknownPlaceholders(
        PaymentMethod $record,
        string $template,
        ?array $details = null,
    ): array {
        if (trim($template) === '') {
            return [];
        }

        preg_match_all('/:([a-z][a-z0-9_]*)/i', $template, $matches);

        $available = self::availableMergeFields($record, $details);

        return array_values(array_unique(array_filter(
            array_map(static fn (string $name): string => ':'.$name, $matches[1]),
            static fn (string $placeholder): bool => ! in_array(ltrim($placeholder, ':'), $available, true),
        )));
    }

    /**
     * @return list<string>
     */
    private static function requiredKeys(PaymentMethod $record): array
    {
        $resolver = app(PaymentAdapterResolverContract::class);

        if (! $resolver->has($record->code)) {
            return [];
        }

        return $resolver->for($record->code)->requiredAccountDetails();
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private static function missingRequiredKeys(PaymentMethod $record, array $details): array
    {
        return array_values(array_filter(
            self::requiredKeys($record),
            static fn (string $key): bool => ! isset($details[$key]) || trim((string) $details[$key]) === '',
        ));
    }

    private static function requiredKeysHint(PaymentMethod $record): string
    {
        $required = self::requiredKeys($record);

        if ($required === []) {
            return 'This method needs no account details — nothing is sent anywhere.';
        }

        return 'Required before customers can choose this method: '.implode(', ', $required)
            .'. You may add others and use them as :merge_fields in the instructions.';
    }

    private static function accountDetailsDescription(PaymentMethod $record): string
    {
        if (self::requiredKeys($record) === []) {
            return 'Nothing to configure. Cash is settled at the counter.';
        }

        return 'Spec §4. Until these are entered the method is switched on in name only — '
            .'it is withheld from checkout, because instructions to send money to a blank account '
            .'number are instructions to send it nowhere.';
    }
}
