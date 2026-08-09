<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleClasses\Tables;

use App\Models\VehicleClass;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The fleet's pricing, with the incomplete classes impossible to miss.
 *
 * The "Sellable" column is the one that matters. A class missing a §15 figure
 * is not merely untidy — it is withheld from every search, so its vehicles
 * cannot be booked at all. Somebody looking at an empty search result needs to
 * be able to find out why from this screen in one glance.
 */
final class VehicleClassesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Class')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (VehicleClass $record): ?string => $record->description),

                TextColumn::make('daily_rate')
                    ->label('Per day')
                    ->money('ZMW')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('insurance_price')
                    ->label('Waiver')
                    ->money('ZMW')
                    ->alignEnd()
                    ->placeholder('Not decided')
                    ->description(fn (VehicleClass $record): ?string => $record->insurance_price === null
                        ? null
                        : ($record->insurance_price_mode->value === 'flat' ? 'per booking' : 'per day')),

                TextColumn::make('insurance_excess_amount')
                    ->label('Excess')
                    ->money('ZMW')
                    ->alignEnd()
                    ->placeholder('Not decided'),

                TextColumn::make('security_deposit_amount')
                    ->label('Security deposit')
                    ->money('ZMW')
                    ->alignEnd()
                    ->placeholder('Not decided'),

                IconColumn::make('is_active')
                    ->label('Offered')
                    ->boolean(),

                // Not decoration. False here means every vehicle in this class
                // is invisible to customers.
                TextColumn::make('id')
                    ->label('Sellable')
                    ->badge()
                    ->state(fn (VehicleClass $record): string => $record->isFullyPriced()
                        ? 'Yes'
                        : 'Needs '.count($record->missingPricingDecisions()))
                    ->color(fn (VehicleClass $record): string => $record->isFullyPriced() ? 'success' : 'danger')
                    ->tooltip(fn (VehicleClass $record): ?string => $record->isFullyPriced()
                        ? null
                        : 'Withheld from search: '.implode('; ', $record->missingPricingDecisions())),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Offered for hire'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Deliberately empty. A class is retired with `is_active`, never
            // deleted — see VehicleClassPolicy — and certainly not in bulk.
            ->toolbarActions([]);
    }
}
