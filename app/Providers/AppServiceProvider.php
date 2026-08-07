<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AuditLoggerContract;
use App\Contracts\AvailabilityServiceContract;
use App\Contracts\BasketServiceContract;
use App\Contracts\BookingCreationServiceContract;
use App\Contracts\BookingReferenceGeneratorContract;
use App\Contracts\BookingStateMachineContract;
use App\Contracts\CustomerResolverContract;
use App\Contracts\PaymentAdapterResolverContract;
use App\Contracts\PaymentConfirmationServiceContract;
use App\Contracts\PaymentDeadlineCalculatorContract;
use App\Contracts\PaymentMethodServiceContract;
use App\Contracts\PaymentRecordingServiceContract;
use App\Contracts\PaymentReferenceGeneratorContract;
use App\Contracts\PhoneNormaliserContract;
use App\Contracts\PricingServiceContract;
use App\Contracts\QuoteServiceContract;
use App\Contracts\ReferenceSequenceContract;
use App\Contracts\SettingsRepositoryContract;
use App\Contracts\VehicleHoldServiceContract;
use App\Services\Audit\AuditLogger;
use App\Services\Availability\AvailabilityService;
use App\Services\Basket\BasketService;
use App\Services\Bookings\BookingCreationService;
use App\Services\Bookings\BookingReferenceGenerator;
use App\Services\Bookings\BookingStateMachine;
use App\Services\Customers\CustomerResolver;
use App\Services\Customers\PhoneNormaliser;
use App\Services\Holds\VehicleHoldService;
use App\Services\Payments\PaymentAdapterResolver;
use App\Services\Payments\PaymentConfirmationService;
use App\Services\Payments\PaymentDeadlineCalculator;
use App\Services\Payments\PaymentMethodService;
use App\Services\Payments\PaymentRecordingService;
use App\Services\Payments\PaymentReferenceGenerator;
use App\Services\Pricing\PricingService;
use App\Services\Pricing\QuoteService;
use App\Services\References\ReferenceSequence;
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
        // Bound rather than shared: this one depends on the session, which is
        // request-scoped. A singleton would capture whichever session existed
        // when it was first resolved and keep serving that one — harmless in a
        // single web request, wrong in a queue worker or a long-lived process.
        $this->app->bind(BasketServiceContract::class, BasketService::class);

        $this->app->singleton(AuditLoggerContract::class, AuditLogger::class);
        $this->app->singleton(BookingCreationServiceContract::class, BookingCreationService::class);
        $this->app->singleton(BookingReferenceGeneratorContract::class, BookingReferenceGenerator::class);
        $this->app->singleton(PaymentAdapterResolverContract::class, PaymentAdapterResolver::class);
        $this->app->singleton(PaymentConfirmationServiceContract::class, PaymentConfirmationService::class);
        $this->app->singleton(PaymentRecordingServiceContract::class, PaymentRecordingService::class);
        $this->app->singleton(PaymentReferenceGeneratorContract::class, PaymentReferenceGenerator::class);
        $this->app->singleton(ReferenceSequenceContract::class, ReferenceSequence::class);
        $this->app->singleton(BookingStateMachineContract::class, BookingStateMachine::class);
        $this->app->singleton(SettingsRepositoryContract::class, SettingsRepository::class);
        $this->app->singleton(CustomerResolverContract::class, CustomerResolver::class);
        $this->app->singleton(PaymentDeadlineCalculatorContract::class, PaymentDeadlineCalculator::class);
        $this->app->singleton(PaymentMethodServiceContract::class, PaymentMethodService::class);
        $this->app->singleton(PhoneNormaliserContract::class, PhoneNormaliser::class);
        $this->app->singleton(PricingServiceContract::class, PricingService::class);
        $this->app->singleton(QuoteServiceContract::class, QuoteService::class);
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
