<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds;

use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\Pages\ViewRefund;
use App\Filament\Resources\Refunds\Schemas\RefundInfolist;
use App\Filament\Resources\Refunds\Tables\RefundsTable;
use App\Models\Refund;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Refunds, read-only.
 *
 * No `form()`, no create page, no edit page — see ARCHITECTURE §11 and
 * `RefundPolicy`. A refund's amount comes from spec §9 and is frozen; its
 * approver is subject to §9.3's two-person rule; its status is derived from a
 * disbursement row protected by a unique key. A Filament form would write past
 * all three, and nothing would fail — the row would simply be wrong, and the
 * wrongness would be money.
 *
 * Everything staff can do to a refund is an action calling a service.
 */
final class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return RefundInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RefundsTable::configure($table);
    }

    /**
     * The number of refunds waiting for somebody, on the navigation item.
     *
     * Both queues together: awaiting approval and approved-but-unpaid. Each one
     * is a customer waiting for money, and a queue nobody can see from the
     * sidebar is a queue that grows.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = Refund::query()->open()->count();

        return $open === 0 ? null : (string) $open;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Refund::query()->awaitingPayout()->exists() ? 'danger' : 'warning';
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
            'index' => ListRefunds::route('/'),
            'view' => ViewRefund::route('/{record}'),
        ];
    }
}
