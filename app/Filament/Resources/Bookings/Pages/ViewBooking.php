<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\Actions\CancelAndRefundAction;
use App\Filament\Resources\Bookings\Actions\ExtendDeadlineAction;
use App\Filament\Resources\Bookings\Actions\TakeBalanceAction;
use App\Filament\Resources\Bookings\BookingResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One booking, read-only.
 *
 * The generated page offered an Edit button. There is no edit page and
 * `BookingPolicy::update()` returns false, so it would have led nowhere — the
 * same actions as the list are offered instead, and each calls a service.
 */
final class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TakeBalanceAction::make(),
            ExtendDeadlineAction::make(),
            CancelAndRefundAction::make(),
        ];
    }
}
