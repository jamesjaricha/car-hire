<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleClasses\Schemas;

use App\Enums\InsurancePriceMode;
use App\Models\Operator;
use App\Models\VehicleClass;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The pricing that everything downstream reads.
 *
 * THE THREE NULLABLE FIELDS ARE THE POINT OF THIS SCREEN
 *
 * `security_deposit_amount`, `insurance_price` and `insurance_excess_amount`
 * are spec §15's items 2, 3 and 4 — figures only the business can answer. They
 * are nullable, and empty means "nobody has decided", not zero. A class holding
 * any of them is withheld from search results entirely, because §6 requires the
 * deposit to be shown from the search page onward and §10 requires the excess to
 * be stated at checkout; rendering either as zero would put a false statement in
 * front of a customer, in writing, and §6 says the deposit must never first
 * appear at the counter.
 *
 * So the fields are deliberately NOT required. Forcing a number would produce
 * exactly the invented figure the null exists to prevent — somebody types a
 * zero to get past the validation and the class quietly becomes sellable.
 *
 * WHY THE TWO DEPOSITS ARE IN DIFFERENT SECTIONS
 *
 * The specification calls conflating them the single most likely misreading.
 * The refundable cash deposit lives here on the class; the 50% booking deposit
 * is a percentage of the grand total and lives in settings. They are never on
 * screen together without their full names.
 */
final class VehicleClassForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The class')
                ->columns(2)
                ->schema([
                    Select::make('operator_id')
                        ->label('Operator')
                        ->relationship('operator', 'name')
                        ->default(fn (): ?int => Operator::query()->value('id'))
                        ->required(),

                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true)
                        // Generated on the way in rather than by a model hook.
                        // DatabaseSeeder runs with WithoutModelEvents, which has
                        // previously left derived columns null on a fresh
                        // migrate-and-seed; every slug in this codebase is set
                        // explicitly for that reason.
                        ->afterStateUpdated(function (?string $state, callable $set, ?VehicleClass $record): void {
                            if ($record === null && $state !== null) {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(120)
                        ->helperText('Unique per operator. Used in URLs; changing it on a live class breaks existing links.'),

                    Toggle::make('is_active')
                        ->label('Available for hire')
                        ->default(true)
                        ->helperText('A class is retired by switching this off. Classes are never deleted — past bookings read through them.'),

                    Textarea::make('description')
                        ->label('Description')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),

            Section::make('Photographs')
                ->description('Shown to customers in search results and on the vehicle page. The first image is the one used on cards — drag to reorder.')
                ->schema([
                    FileUpload::make('image_paths')
                        ->label('Images')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->imageEditor()
                        ->directory('vehicle-classes')
                        // The public disk, because these are shown to anonymous
                        // visitors on the search page. Requires
                        // `php artisan storage:link` once per environment.
                        ->disk('public')
                        ->maxFiles(6)
                        ->maxSize(4096)
                        ->helperText(
                            'Optional. The site is designed to work without photographs — a class with none '
                            .'shows an illustrated silhouette rather than a broken card — but a real photograph '
                            .'of the actual vehicle is the single biggest thing that makes a customer trust a booking.'
                        ),
                ]),

            Section::make('What the hire costs')
                ->columns(2)
                ->schema([
                    TextInput::make('daily_rate')
                        ->label('Daily rate')
                        ->prefix('ZMW')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText('Individual vehicles may override this. Changing it affects new quotes only — existing bookings keep the rate they were sold at.'),

                    TextInput::make('turnaround_buffer_minutes')
                        ->label('Turnaround buffer (minutes)')
                        ->numeric()
                        ->minValue(0)
                        ->default(120)
                        ->required()
                        ->helperText('Clear time required between hires for cleaning and inspection. Takes effect on the next availability search.'),
                ]),

            Section::make('Damage waiver')
                ->description('Spec §10: mandatory, not declinable, and included in every displayed price. The excess must be stated at checkout.')
                ->columns(2)
                ->schema([
                    self::undecidableMoney('insurance_price', 'Waiver price')
                        ->helperText('Charged per day or once per booking, depending on the mode beside it. Leave empty if the business has not decided.'),

                    Select::make('insurance_price_mode')
                        ->label('Charged')
                        ->options([
                            InsurancePriceMode::PerDay->value => 'Per day',
                            InsurancePriceMode::Flat->value => 'Once per booking',
                        ])
                        ->default(InsurancePriceMode::PerDay->value)
                        ->required(),

                    self::undecidableMoney('insurance_excess_amount', 'Excess the customer carries')
                        ->helperText('What the customer remains liable for after a claim. Spec §10 requires this at checkout, so a class without it cannot be sold.')
                        ->columnSpanFull(),
                ]),

            Section::make('Refundable security deposit')
                ->description('Spec §6: cash taken at the branch on pickup and returned on a clean return. NOT the 50% booking deposit, which part-pays the hire and lives in Settings.')
                ->schema([
                    self::undecidableMoney('security_deposit_amount', 'Security deposit')
                        ->helperText('Shown in search results, at checkout, in the confirmation email and in the T&Cs. Spec §6 says it must never first appear at the counter, so a class without it is withheld from sale.'),
                ]),
        ]);
    }

    /**
     * A money field where empty means "undecided" rather than zero.
     *
     * Filament hands back an empty string for a cleared numeric input. Stored
     * as-is that becomes 0.00 through the decimal cast, which is precisely the
     * ambiguity the nullable columns exist to remove — so it is normalised to a
     * real null on the way out.
     */
    private static function undecidableMoney(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('ZMW')
            ->numeric()
            ->minValue(0)
            ->nullable()
            ->placeholder('Not yet decided')
            ->dehydrateStateUsing(
                fn (mixed $state): ?string => $state === null || $state === '' ? null : (string) $state
            );
    }
}
