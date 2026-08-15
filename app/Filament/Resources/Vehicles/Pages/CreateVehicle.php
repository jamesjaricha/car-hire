<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\VehicleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;
}
