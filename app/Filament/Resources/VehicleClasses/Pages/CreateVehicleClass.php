<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleClasses\Pages;

use App\Filament\Resources\VehicleClasses\VehicleClassResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A new class starts unsellable, and that is intended.
 *
 * The three §15 figures are nullable and not required, so a class created
 * without them is withheld from search until somebody decides them. Requiring
 * them here would produce the invented number the null exists to prevent —
 * a zero typed to satisfy validation, and a class that is quietly sellable with
 * "no deposit required" published to customers.
 */
final class CreateVehicleClass extends CreateRecord
{
    protected static string $resource = VehicleClassResource::class;
}
