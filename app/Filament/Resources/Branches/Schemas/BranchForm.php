<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Schemas;

use App\Models\Operator;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One place a customer collects a car from.
 *
 * THE HOURS ARE NULLABLE ON PURPOSE, AND THAT IS NOT AN OVERSIGHT
 *
 * `opens_at` and `closes_at` have been nullable since the first migration,
 * whose docblock says why: spec §15.8 leaves operating hours to the business,
 * and forcing a guess into the schema produces exactly the invented figure the
 * null exists to prevent. The same reasoning as the §15 pricing fields — see
 * ARCHITECTURE §14 — with one important difference.
 *
 * A missing PRICE withholds a class from sale, because publishing a wrong one
 * takes money on false terms. Missing HOURS withhold nothing: the branch still
 * trades, customers still book, and the locations page simply says the hours
 * are not published rather than inventing "08:00–17:00". A blank is honest; a
 * guess would have somebody drive to a closed gate.
 *
 * WHY `code` IS NOT EDITABLE AFTER CREATION
 *
 * It is unique per operator and is what `DemoFleetSeeder` keys on with
 * `firstOrCreate`. Renaming a code would make the next seeder run create a
 * duplicate branch rather than find the existing one — and re-seeding is
 * something this project does on every deploy for permissions and may do again
 * for demo data. The display name is free to change; the key is not.
 */
final class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The branch')
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
                        ->maxLength(120)
                        ->helperText('Shown to customers in the search form and on the locations page.'),

                    TextInput::make('code')
                        ->label('Code')
                        ->required()
                        ->maxLength(16)
                        ->unique(ignoreRecord: true)
                        // Disabled on edit rather than hidden: somebody needs to
                        // be able to READ it when matching a branch against a
                        // seeder or a support conversation.
                        ->disabled(fn (?string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (?string $operation): bool => $operation !== 'edit')
                        ->helperText('A short key, unique per operator. Fixed once created — seeders match on it.'),

                    TextInput::make('city')
                        ->label('City')
                        ->required()
                        ->maxLength(80),

                    TextInput::make('address')
                        ->label('Address')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('Optional, and shown publicly. A customer collecting a car needs to find the gate.'),

                    TextInput::make('phone_e164')
                        ->label('Telephone')
                        ->tel()
                        ->maxLength(20)
                        ->helperText('Shown publicly. Best written in full international form, e.g. +260 97 1234567.'),

                    Toggle::make('is_active')
                        ->label('Open for business')
                        ->default(true)
                        ->helperText('Switching this off removes the branch from the search form and the locations page. Branches are never deleted — past bookings read through them.'),
                ]),

            Section::make('Opening hours')
                ->description('Spec §15.8, and still the operator\'s decision. Leave both empty and the site says hours are not published rather than inventing them — a guess here has somebody drive to a closed gate.')
                ->columns(3)
                ->schema([
                    TimePicker::make('opens_at')
                        ->label('Opens')
                        ->seconds(false)
                        ->nullable(),

                    TimePicker::make('closes_at')
                        ->label('Closes')
                        ->seconds(false)
                        ->nullable()
                        // Coherence only, not policy. A branch genuinely open
                        // past midnight would need a different model than two
                        // times on one row, and nobody has asked for one.
                        ->after('opens_at')
                        ->helperText('Must be after opening. Overnight hours are not modelled.'),

                    Toggle::make('after_hours_pickup')
                        ->label('After-hours collection by arrangement')
                        ->helperText('Says on the locations page that collection outside these hours can be arranged. It does not change what the booking engine allows.'),
                ]),
        ]);
    }
}
