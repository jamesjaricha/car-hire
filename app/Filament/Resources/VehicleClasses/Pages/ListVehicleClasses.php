<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleClasses\Pages;

use App\Filament\Resources\VehicleClasses\VehicleClassResource;
use App\Models\VehicleClass;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The fleet's pricing, with the unsellable classes first.
 */
final class ListVehicleClasses extends ListRecords
{
    protected static string $resource = VehicleClassResource::class;

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

        // Only shown when there is something in it. A permanently empty red tab
        // is the fastest way to teach somebody to stop looking at red tabs.
        if (VehicleClass::query()->awaitingPricingDecisions()->exists()) {
            $tabs['awaiting_decisions'] = Tab::make('Needs pricing decisions')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingPricingDecisions())
                ->badge(fn (): int => VehicleClass::query()->awaitingPricingDecisions()->count())
                ->badgeColor('danger');
        }

        $tabs['all'] = Tab::make('All');

        $tabs['offered'] = Tab::make('Offered for hire')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->active());

        return $tabs;
    }
}
