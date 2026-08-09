<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Pages;

use App\Filament\Resources\Refunds\RefundResource;
use App\Models\Refund;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The refunds list, and the two queues that matter.
 *
 * Both default tabs hold customers waiting for money — one waiting for a second
 * person to agree, the other waiting for somebody to actually send it. They are
 * first because a refund that nobody looks at is indistinguishable, from the
 * customer's side, from a refund that was refused.
 *
 * No header actions: refunds are raised against a booking, from the booking
 * screens. `RefundPolicy` would refuse a create anyway, and offering a button
 * that cannot work teaches staff to distrust the panel.
 */
final class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        return [
            'awaiting_approval' => Tab::make('Awaiting approval')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingApproval())
                ->badge(fn (): int => Refund::query()->awaitingApproval()->count())
                ->badgeColor('warning'),

            // The operator has agreed to give this money back and still has it.
            // Louder than the approval queue on purpose: the decision is made
            // and the customer is simply waiting.
            'awaiting_payout' => Tab::make('Approved, not yet paid')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingPayout())
                ->badge(fn (): int => Refund::query()->awaitingPayout()->count())
                ->badgeColor('danger'),

            'all' => Tab::make('All'),
        ];
    }
}
