<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleClasses\Pages;

use App\Filament\Resources\VehicleClasses\VehicleClassResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action in the header.
 *
 * `VehicleClassPolicy::delete()` returns false, so Filament would hide it
 * anyway; declaring no header actions means it is not there to be re-enabled by
 * somebody adding an unrelated button later. A class is retired with
 * `is_active` — past bookings read their history through it.
 */
final class EditVehicleClass extends EditRecord
{
    protected static string $resource = VehicleClassResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
