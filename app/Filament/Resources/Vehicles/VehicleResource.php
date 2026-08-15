<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles;

use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Schemas\VehicleForm;
use App\Filament\Resources\Vehicles\Tables\VehiclesTable;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The physical fleet — the second resource in this panel with real forms.
 *
 * ARCHITECTURE §11 permits CRUD where no service owns the writes, and a vehicle
 * qualifies: it is a car in a yard, described by columns somebody types. What
 * makes it need care is the pair of price overrides, which are gated in
 * `VehicleForm` rather than here.
 *
 * There is no delete page. See `VehiclePolicy`.
 *
 * Sorted after vehicle classes in the navigation on purpose: a vehicle cannot
 * exist without a class to put it in, so the screens appear in the order
 * somebody setting up a fleet would need them.
 */
final class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $recordTitleAttribute = 'registration';

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Vehicles';

    public static function form(Schema $schema): Schema
    {
        return VehicleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehiclesTable::configure($table);
    }

    /**
     * How many vehicles are off the road.
     *
     * On the navigation item because it is otherwise invisible: a vehicle in
     * maintenance simply stops appearing in searches, with nothing anywhere
     * saying why the fleet looks smaller than it is. Retired vehicles are not
     * counted — those are gone on purpose and would make the badge permanent.
     */
    public static function getNavigationBadge(): ?string
    {
        $offRoad = Vehicle::query()
            ->where('status', VehicleStatus::Maintenance)
            ->count();

        return $offRoad === 0 ? null : (string) $offRoad;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
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
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
