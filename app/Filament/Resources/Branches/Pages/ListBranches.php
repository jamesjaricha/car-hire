<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\Branch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every branch, with the ones missing an answer easy to find.
 */
final class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

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
        // the fastest way to teach somebody to stop looking at tabs — the same
        // rule ListVehicles follows.
        if (Branch::query()->active()->withoutPublishedHours()->exists()) {
            $tabs['no_hours'] = Tab::make('Hours not published')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->active()->withoutPublishedHours())
                ->badge(fn (): int => Branch::query()->active()->withoutPublishedHours()->count())
                ->badgeColor('warning');
        }

        $tabs['open'] = Tab::make('Open')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->active());

        $tabs['all'] = Tab::make('All');

        return $tabs;
    }
}
