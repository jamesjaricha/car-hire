<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleClasses;

use App\Filament\Resources\VehicleClasses\Pages\CreateVehicleClass;
use App\Filament\Resources\VehicleClasses\Pages\EditVehicleClass;
use App\Filament\Resources\VehicleClasses\Pages\ListVehicleClasses;
use App\Filament\Resources\VehicleClasses\Schemas\VehicleClassForm;
use App\Filament\Resources\VehicleClasses\Tables\VehicleClassesTable;
use App\Models\VehicleClass;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Vehicle classes — the first resource in this panel with real forms.
 *
 * ARCHITECTURE §11 forbids CRUD where a service owns the writes, and permits it
 * where nothing does. A class is a row of figures somebody types: no state
 * machine, no locks, no ledger. The reason it still needs care is that those
 * figures are read by `PricingService` on every quote, so this screen decides
 * what every customer is charged.
 *
 * There is no delete page. See `VehicleClassPolicy`.
 */
final class VehicleClassResource extends Resource
{
    protected static ?string $model = VehicleClass::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Vehicle classes';

    public static function form(Schema $schema): Schema
    {
        return VehicleClassForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehicleClassesTable::configure($table);
    }

    /**
     * How many classes are unsellable for want of a §15 decision.
     *
     * On the navigation item because the consequence is invisible everywhere
     * else: the vehicles simply do not appear in search results, and nothing
     * else in the panel would ever mention it.
     */
    public static function getNavigationBadge(): ?string
    {
        $incomplete = VehicleClass::query()->awaitingPricingDecisions()->count();

        return $incomplete === 0 ? null : (string) $incomplete;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
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
        return [
            'index' => ListVehicleClasses::route('/'),
            'create' => CreateVehicleClass::route('/create'),
            'edit' => EditVehicleClass::route('/{record}/edit'),
        ];
    }
}
