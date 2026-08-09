<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Pages;

use App\Filament\Resources\Refunds\Actions\ApproveRefundAction;
use App\Filament\Resources\Refunds\Actions\DisburseRefundAction;
use App\Filament\Resources\Refunds\Actions\RejectRefundAction;
use App\Filament\Resources\Refunds\RefundResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One refund, read-only.
 *
 * The three actions are the same ones offered on the list. This is the screen
 * somebody opens before approving, which is the point at which the §9 working
 * and the §15.1 placeholder warning need to be in front of them.
 */
final class ViewRefund extends ViewRecord
{
    protected static string $resource = RefundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApproveRefundAction::make(),
            RejectRefundAction::make(),
            DisburseRefundAction::make(),
        ];
    }
}
