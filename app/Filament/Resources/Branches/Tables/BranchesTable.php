<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Tables;

use App\Models\Branch;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Where the operator trades from.
 *
 * The column doing real work is "Hours". A branch with none published is not
 * broken — it still sells, and the locations page says so honestly rather than
 * inventing times — but it IS an unanswered §15.8 question, and the only place
 * anybody would notice is a customer wondering what time to collect.
 */
final class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Branch $record): ?string => $record->address),

                TextColumn::make('city')
                    ->label('City')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('phone_e164')
                    ->label('Telephone')
                    ->placeholder('Not published'),

                // Warning, never danger — matching the Photos columns. An
                // unpublished hour stops nothing being booked; it is a gap in
                // what a customer is told, not a fault in what they can do.
                TextColumn::make('opens_at')
                    ->label('Hours')
                    ->badge()
                    ->state(fn (Branch $record): string => $record->openingHoursLabel() ?? 'Not published')
                    ->color(fn (Branch $record): string => $record->publishesHours() ? 'success' : 'warning')
                    ->tooltip(fn (Branch $record): ?string => $record->publishesHours()
                        ? null
                        : 'Spec §15.8 is unanswered for this branch. Customers are told the hours are not published rather than being given a guess.'),

                IconColumn::make('after_hours_pickup')
                    ->label('After hours')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Open')
                    ->boolean(),

                TextColumn::make('vehicles_count')
                    ->label('Vehicles')
                    ->counts('vehicles')
                    ->alignEnd(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Open for business'),

                // The working queue for whoever is chasing the §15.8 answer.
                Filter::make('without_hours')
                    ->label('Hours not published')
                    ->query(fn (EloquentBuilder $query): EloquentBuilder => $query->withoutPublishedHours()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Deliberately empty. A branch is closed with `is_active`, never
            // deleted — see BranchPolicy — and certainly not in bulk.
            ->toolbarActions([]);
    }
}
