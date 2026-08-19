<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Branches\Schemas\BranchForm;
use App\Filament\Resources\Branches\Tables\BranchesTable;
use App\Models\Branch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where the operator trades from.
 *
 * ARCHITECTURE §11 permits real CRUD where no service owns the writes, and a
 * branch qualifies plainly: a name, an address, a telephone number and two
 * times. No state machine, no locks, no ledger.
 *
 * Gated on `settings.manage` rather than a permission of its own — spec §15.8
 * is "Branch list, operating hours, after-hours pickup policy", which is
 * configuration. See `BranchPolicy` for why that matters beyond tidiness.
 *
 * There is no delete page. A branch is closed with `is_active`.
 *
 * Sorted before vehicle classes and vehicles: somebody setting this platform up
 * creates the places before the cars that sit in them, and the navigation reads
 * in that order.
 */
final class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 29;

    protected static ?string $navigationLabel = 'Branches';

    public static function form(Schema $schema): Schema
    {
        return BranchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchesTable::configure($table);
    }

    /**
     * How many branches have not published their hours.
     *
     * Spec §15.8 is an open item, and this is the only place it surfaces. A
     * customer meeting the gap sees "opening hours not published", which is
     * honest but is not an answer — and nothing else in the panel would ever
     * mention it.
     */
    public static function getNavigationBadge(): ?string
    {
        $unpublished = Branch::query()->active()->withoutPublishedHours()->count();

        return $unpublished === 0 ? null : (string) $unpublished;
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
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
