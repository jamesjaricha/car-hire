<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action in the header.
 *
 * `BranchPolicy::delete()` returns false, so Filament would hide it anyway;
 * declaring no header actions means it is not there to be re-enabled by
 * somebody adding an unrelated button later. `vehicles` references `branches`
 * with `restrictOnDelete`, and a booking's collection point reads through it —
 * a hire collected from Livingstone in March must still say Livingstone next
 * year. `is_active` is the off switch.
 */
final class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
