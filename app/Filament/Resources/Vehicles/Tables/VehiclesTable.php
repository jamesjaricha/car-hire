<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Tables;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The fleet list.
 *
 * Two columns are doing more than they look. "Status" decides whether a vehicle
 * appears in any search at all, and "Rate" says whether this car is priced
 * differently from the rest of its class — an override is easy to set and then
 * forget, and the only other place it surfaces is the customer's quote.
 */
final class VehiclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('registration')
            ->columns([
                TextColumn::make('registration')
                    ->label('Registration')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('make')
                    ->label('Vehicle')
                    ->searchable(['make', 'model'])
                    ->sortable()
                    ->state(fn (Vehicle $record): string => trim($record->make.' '.$record->model))
                    ->description(fn (Vehicle $record): ?string => $record->year === null
                        ? null
                        : (string) $record->year),

                TextColumn::make('vehicleClass.name')
                    ->label('Class')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable(),

                TextColumn::make('seats')
                    ->label('Seats')
                    ->alignEnd()
                    ->placeholder('—'),

                // An override is the exception, so the ordinary case says so
                // plainly rather than repeating the class figure as though it
                // had been chosen here.
                TextColumn::make('daily_rate')
                    ->label('Rate')
                    ->alignEnd()
                    ->badge()
                    ->state(fn (Vehicle $record): string => $record->daily_rate === null
                        ? 'Class rate'
                        : 'ZMW '.number_format((float) $record->daily_rate, 2))
                    ->color(fn (Vehicle $record): string => $record->daily_rate === null ? 'gray' : 'warning')
                    ->tooltip(fn (Vehicle $record): ?string => $record->daily_rate === null
                        ? null
                        : 'Priced separately from its class. Only a Super Admin can change this.'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Vehicle $record): string => $record->status->label())
                    ->color(fn (Vehicle $record): string => match ($record->status) {
                        VehicleStatus::Available => 'success',
                        VehicleStatus::Maintenance => 'warning',
                        VehicleStatus::Retired => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => self::statusOptions()),

                SelectFilter::make('branch')
                    ->label('Branch')
                    ->relationship('branch', 'name'),

                SelectFilter::make('vehicleClass')
                    ->label('Class')
                    ->relationship('vehicleClass', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Deliberately empty. A vehicle is retired with `status`, never
            // deleted — see VehiclePolicy — and certainly not in bulk.
            ->toolbarActions([]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (VehicleStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
