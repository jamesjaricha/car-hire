<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AvailabilityServiceContract;
use App\Contracts\PricingServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Contracts\VehicleHoldServiceContract;
use App\Services\Availability\AvailabilityService;
use App\Services\Holds\VehicleHoldService;
use App\Services\Pricing\PricingService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Domain services are bound to contracts so callers depend on behaviour
     * rather than on a concrete class, and so tests can swap them.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsRepositoryContract::class, SettingsRepository::class);
        $this->app->singleton(PricingServiceContract::class, PricingService::class);
        $this->app->singleton(AvailabilityServiceContract::class, AvailabilityService::class);
        $this->app->singleton(VehicleHoldServiceContract::class, VehicleHoldService::class);
    }

    public function boot(): void
    {
        // Strict mode outside production: surfaces N+1 queries, silently
        // discarded attributes and access to attributes that were never
        // selected. All three are the kind of fault that stays invisible until
        // it is expensive.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
