<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Tables;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Filament\Resources\Refunds\Actions\ApproveRefundAction;
use App\Filament\Resources\Refunds\Actions\DisburseRefundAction;
use App\Filament\Resources\Refunds\Actions\RejectRefundAction;
use App\Models\Refund;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The refunds list.
 *
 * No edit action and no bulk actions — see `RefundPolicy`. The only things that
 * change a refund here are the three actions at the end, and each calls a
 * service.
 *
 * The admin fee column carries its own warning. A fee of K0.00 looks like a
 * decision until you know it is a §15.1 placeholder, and this is a list somebody
 * scans before approving money.
 */
final class RefundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first. These are queues, and the person who has been
            // waiting longest should be at the top of them.
            ->defaultSort('requested_at')
            ->columns([
                TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('booking.customer.full_name')
                    ->label('Customer')
                    ->searchable()
                    ->placeholder('Not linked'),

                TextColumn::make('reason')
                    ->label('Why')
                    ->badge()
                    ->formatStateUsing(fn (RefundReason $state): string => $state->label()),

                TextColumn::make('amount_paid_at_request')
                    ->label('Was holding')
                    ->money('ZMW')
                    ->alignEnd(),

                TextColumn::make('admin_fee_deducted')
                    ->label('Admin fee')
                    ->money('ZMW')
                    ->alignEnd()
                    // The §15.1 warning, on the figure it applies to.
                    ->description(fn (Refund $record): ?string => $record->admin_fee_was_placeholder
                        ? 'PLACEHOLDER — not yet decided'
                        : null)
                    ->color(fn (Refund $record): ?string => $record->admin_fee_was_placeholder ? 'warning' : null),

                TextColumn::make('amount')
                    ->label('Refund')
                    ->money('ZMW')
                    ->weight('bold')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (RefundStatus $state): string => $state->label())
                    ->color(fn (RefundStatus $state): string => match ($state) {
                        RefundStatus::Requested => 'warning',
                        // Money agreed and still here. The loudest state on the
                        // screen, because it is the one with somebody waiting.
                        RefundStatus::Approved => 'danger',
                        RefundStatus::Disbursed => 'success',
                        RefundStatus::Rejected => 'gray',
                    }),

                TextColumn::make('requestedBy.name')
                    ->label('Raised by')
                    ->toggleable(),

                TextColumn::make('requested_at')
                    ->label('Raised')
                    ->dateTime('j M Y H:i')
                    ->timezone(config('carhire.display_timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => collect(RefundStatus::cases())
                        ->mapWithKeys(fn (RefundStatus $case): array => [$case->value => $case->label()])
                        ->all()),

                SelectFilter::make('reason')
                    ->label('Reason')
                    ->options(fn (): array => collect(RefundReason::cases())
                        ->mapWithKeys(fn (RefundReason $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                ApproveRefundAction::make(),
                RejectRefundAction::make(),
                DisburseRefundAction::make(),
            ])
            // Deliberately empty. Refunds are never actioned in bulk — §9.3 puts
            // a person on each one, and a bulk approve is a way to sign off
            // money without reading it.
            ->toolbarActions([]);
    }
}
