<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Tables;

use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\Actions\ExtendDeadlineAction;
use App\Filament\Resources\Bookings\Actions\TakeBalanceAction;
use App\Models\Booking;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The booking list.
 *
 * No edit action and no bulk delete — see BookingPolicy. The only things that
 * change a booking here are the two actions at the end, and both call services.
 */
final class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('pickup_at')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    // The number staff read aloud and match against a bank
                    // statement. Copyable because it is retyped constantly.
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('customer.full_name')
                    ->label('Customer')
                    ->searchable()
                    ->placeholder('Not linked'),

                TextColumn::make('vehicle_registration')
                    ->label('Vehicle')
                    ->description(fn (Booking $record): string => $record->vehicle_class_name)
                    ->searchable(),

                TextColumn::make('pickup_at')
                    ->label('Pickup')
                    ->dateTime('j M Y H:i')
                    ->timezone(config('carhire.display_timezone'))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Booking')
                    ->badge()
                    ->formatStateUsing(fn (BookingStatus $state): string => $state->label()),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (BookingPaymentStatus $state): string => $state->label()),

                TextColumn::make('balance_due')
                    ->label('Outstanding')
                    ->money('ZMW')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('payment_deadline_at')
                    ->label('Pay by')
                    ->dateTime('j M Y H:i')
                    ->timezone(config('carhire.display_timezone'))
                    ->sortable()
                    // Null is meaningful: a short-notice booking has no
                    // deadline because it is settled at the counter. Spec §8.2.
                    ->placeholder('At the counter')
                    ->color(fn (Booking $record): ?string => $record->payment_deadline_at !== null
                        && $record->status === BookingStatus::PendingPayment
                        && $record->payment_deadline_at->isPast()
                            ? 'danger'
                            : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Booking status')
                    ->options(fn (): array => collect(BookingStatus::cases())
                        ->mapWithKeys(fn (BookingStatus $case): array => [$case->value => $case->label()])
                        ->all()),

                SelectFilter::make('payment_status')
                    ->label('Payment position')
                    ->options(fn (): array => collect(BookingPaymentStatus::cases())
                        ->mapWithKeys(fn (BookingPaymentStatus $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                TakeBalanceAction::make(),
                ExtendDeadlineAction::make(),
            ])
            // Deliberately empty. The generated resource offered a delete bulk
            // action; a booking is cancelled, never deleted, and never in bulk.
            ->toolbarActions([]);
    }
}
