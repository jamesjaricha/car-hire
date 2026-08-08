<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Pages;

use App\Enums\BookingStatus;
use App\Enums\StaffPermission;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The booking list, and the queue this whole slice exists for.
 *
 * No header actions: bookings are created by customers at checkout, not by
 * staff in a panel. The generated page offered a Create button, which
 * `BookingPolicy` would refuse anyway — removing it means staff are not shown
 * something that cannot work.
 */
final class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('All'),

            'awaiting_payment' => Tab::make('Awaiting payment')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingPayment())
                ->badge(fn (): int => Booking::query()->awaitingPayment()->count()),
        ];

        // The queue. Only shown to people who can do something about it —
        // every action on these bookings is Branch Manager and above, and a
        // list of problems you cannot touch invites chasing customers
        // off-system, which is how a queue stops reflecting reality.
        if ($this->mayActOnStalledBookings()) {
            $tabs['stalled'] = Tab::make('Needs a decision')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->stalledAfterDeadline())
                ->badge(fn (): int => Booking::query()->stalledAfterDeadline()->count())
                // These are customers whose money the operator is holding while
                // their deadline has passed. The colour is not decoration.
                ->badgeColor('danger');
        }

        $tabs['confirmed'] = Tab::make('Confirmed')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', BookingStatus::Confirmed));

        $tabs['upcoming'] = Tab::make('Collecting soon')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::AwaitingCrossBorder])
                ->whereBetween('pickup_at', [
                    CarbonImmutable::now(),
                    CarbonImmutable::now()->addDay(),
                ]));

        return $tabs;
    }

    private function mayActOnStalledBookings(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasPermissionTo(StaffPermission::PaymentsExtendDeadline);
    }
}
