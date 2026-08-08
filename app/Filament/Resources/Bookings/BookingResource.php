<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Bookings\Schemas\BookingInfolist;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Bookings, read-only.
 *
 * There is no `form()` and there are no create or edit pages, by design rather
 * than by omission — see ARCHITECTURE §11 and `BookingPolicy`. A booking's
 * status belongs to `BookingStateMachine`, its money to
 * `PaymentConfirmationService`, its deadline to
 * `PaymentDeadlineExtensionService`. A Filament form would write past all
 * three, and nothing would fail; the row would simply be wrong.
 *
 * Everything staff can DO to a booking is an action on the table, and every one
 * of those calls a service.
 */
final class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return BookingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingsTable::configure($table);
    }

    /**
     * @return array<class-string, mixed>
     */
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        // Index and view only. Adding 'create' or 'edit' here would give
        // Filament routes it must not have, whatever the policy says.
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }
}
