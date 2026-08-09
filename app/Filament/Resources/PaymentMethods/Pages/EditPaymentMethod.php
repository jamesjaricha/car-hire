<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action. A method is switched off with `enabled`; deleting the row
 * would leave historic payments pointing at a code nothing can describe.
 */
final class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
