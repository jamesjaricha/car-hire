<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Tables;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * The fleet list.
 *
 * Three columns are doing more than they look. "Status" decides whether a
 * vehicle appears in any search at all; "Rate" says whether this car is priced
 * differently from the rest of its class — an override is easy to set and then
 * forget, and the only other place it surfaces is the customer's quote; and
 * "Photos" says whether a customer looking at this car is being shown this car.
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

                // Never `danger`, matching the same column on Vehicle classes.
                // A car with no photograph still sells — it shows the
                // illustration — so this is a presentation gap, and giving it
                // the red the Sellable column needs would flatten "this cannot
                // be booked" into "this could look better".
                //
                // Two states, not three. It briefly distinguished "borrowing
                // its class's pictures", which stopped existing on 2026-08-18
                // when class photographs became a home-page-only thing. A car
                // now has its own photographs or it has none.
                TextColumn::make('image_paths')
                    ->label('Photos')
                    ->badge()
                    ->state(fn (Vehicle $record): string => $record->hasOwnImages()
                        ? (string) count($record->ownImagePaths())
                        : 'None')
                    ->color(fn (Vehicle $record): string => $record->hasOwnImages() ? 'success' : 'warning')
                    ->tooltip(fn (Vehicle $record): ?string => $record->hasOwnImages()
                        ? null
                        : 'Customers see an illustration rather than this car. Its class photographs are not used here.'),

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

                // The working queue for whoever is photographing the fleet,
                // mirroring the filter on Vehicle classes. Asks about the
                // vehicle's own column only: a car inheriting its class's
                // pictures is exactly what this list is for.
                Filter::make('without_images')
                    ->label('Awaiting its own photograph')
                    ->query(fn (EloquentBuilder $query): EloquentBuilder => $query->withoutImages()),
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
