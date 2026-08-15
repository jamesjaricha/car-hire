<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Pages;

use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Vehicle;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The fleet, with the cars that are off the road easy to find.
 */
final class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];

        // Only shown when there is something in it. A permanently empty tab is
        // the fastest way to teach somebody to stop looking at tabs.
        if (Vehicle::query()->where('status', VehicleStatus::Maintenance)->exists()) {
            $tabs['maintenance'] = Tab::make('Off the road')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', VehicleStatus::Maintenance))
                ->badge(fn (): int => Vehicle::query()->where('status', VehicleStatus::Maintenance)->count())
                ->badgeColor('warning');
        }

        $tabs['bookable'] = Tab::make('Bookable')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->bookable());

        $tabs['all'] = Tab::make('All');

        return $tabs;
    }
}
