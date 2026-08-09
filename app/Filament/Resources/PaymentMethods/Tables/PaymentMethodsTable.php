<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Tables;

use App\Contracts\PaymentAdapterResolverContract;
use App\Models\PaymentMethod;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The six methods of spec §3, and whether each one actually works.
 *
 * "Offered to customers" is the column that matters. A method can be switched
 * on, pass its feature flag, and still not be offered because nobody entered
 * the account details — and from the customer's side that is indistinguishable
 * from it being switched off.
 */
final class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label('Method')
                    ->weight('bold')
                    ->description(fn (PaymentMethod $record): string => $record->code->value),

                IconColumn::make('enabled')
                    ->label('Switched on')
                    ->boolean(),

                TextColumn::make('hold_duration_hours')
                    ->label('Holds for')
                    ->suffix(' hours')
                    ->alignEnd(),

                TextColumn::make('id')
                    ->label('Offered to customers')
                    ->badge()
                    ->state(fn (PaymentMethod $record): string => self::availability($record))
                    ->color(fn (PaymentMethod $record): string => match (self::availability($record)) {
                        'Yes' => 'success',
                        'Not configured' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (PaymentMethod $record): ?string => self::availability($record) === 'Not configured'
                        ? 'Missing: '.implode(', ', self::missing($record))
                        : null),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // The six rows are the six enum cases. No create, no delete — see
            // PaymentMethodPolicy.
            ->toolbarActions([]);
    }

    private static function availability(PaymentMethod $record): string
    {
        if (! $record->isOfferable()) {
            return 'Switched off';
        }

        return self::missing($record) === [] ? 'Yes' : 'Not configured';
    }

    /**
     * @return list<string>
     */
    private static function missing(PaymentMethod $record): array
    {
        $resolver = app(PaymentAdapterResolverContract::class);

        if (! $resolver->has($record->code)) {
            return [];
        }

        return $resolver->for($record->code)->missingAccountDetails($record);
    }
}
