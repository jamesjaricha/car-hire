<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff panel, at /admin.
 *
 * WHAT THIS PANEL IS NOT ALLOWED TO BECOME
 *
 * Filament's usual idiom is a resource per table with create and edit forms
 * that write straight to models. That idiom is incompatible with how this
 * application keeps itself correct. Three phases of work put every dangerous
 * write behind a service — `VehicleHoldService::place()` owns holds,
 * `PaymentConfirmationService` owns confirmations, `BookingStateMachine` owns
 * statuses, `AuditLogger` owns the trail — and a generated resource with an
 * editable `status` dropdown bypasses all four in a single click.
 *
 * So bookings, payments and the audit log get READ-ONLY resources. Every
 * mutation is an explicit action that calls the service and lets its domain
 * exception surface as a notification. Only genuinely CRUD-shaped things —
 * fleet, branches, payment methods, settings, users — get real forms.
 *
 * See ARCHITECTURE.md before adding a resource.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                // FilamentInfoWidget is deliberately not registered. It reports
                // the installed Filament version to anyone who reaches the
                // dashboard, which is free reconnaissance on a panel that will
                // handle payments.
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
