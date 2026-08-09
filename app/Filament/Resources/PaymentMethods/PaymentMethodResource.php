<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods;

use App\Contracts\PaymentAdapterResolverContract;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Resources\PaymentMethods\Schemas\PaymentMethodForm;
use App\Filament\Resources\PaymentMethods\Tables\PaymentMethodsTable;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Spec §3 and §4: which methods are accepted, and what customers are told.
 *
 * Edit only. The six rows are the six cases of `PaymentMethodCode`, each mapped
 * to an adapter — see `PaymentMethodPolicy` for why creating or deleting one is
 * refused.
 */
final class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Payment methods';

    public static function form(Schema $schema): Schema
    {
        return PaymentMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentMethodsTable::configure($table);
    }

    /**
     * Methods switched on but unusable, on the navigation item.
     *
     * The consequence is otherwise invisible from the panel: the method simply
     * stops appearing at checkout, and nothing says why.
     */
    public static function getNavigationBadge(): ?string
    {
        $resolver = app(PaymentAdapterResolverContract::class);

        $unconfigured = PaymentMethod::query()
            ->enabled()
            ->get()
            ->filter(fn (PaymentMethod $method): bool => $method->isOfferable()
                && $resolver->has($method->code)
                && ! $resolver->for($method->code)->isConfigured($method))
            ->count();

        return $unconfigured === 0 ? null : (string) $unconfigured;
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
            'index' => ListPaymentMethods::route('/'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
