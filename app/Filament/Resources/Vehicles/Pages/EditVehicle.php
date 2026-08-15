<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\VehicleResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action in the header.
 *
 * `VehiclePolicy::delete()` returns false, so Filament would hide it anyway;
 * declaring no header actions means it is not there to be re-enabled by
 * somebody adding an unrelated button later. A vehicle is taken off the road
 * with `status` — `vehicle_holds` and `bookings` both reference it with
 * `restrictOnDelete`, and a booking's history reads through its vehicle.
 */
final class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
