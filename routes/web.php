<?php

declare(strict_types=1);

use App\Http\Controllers\BasketController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\VehicleClassController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

/*
| The customer-facing site.
|--------------------------------------------------------------------------
|
| Guest-first throughout. Spec §1.1 and §1.3: a visitor can search, see real
| prices and reach a quote without entering a single personal detail, and is
| asked for contact details only at final checkout.
|
| Every route here is a plain GET or POST rather than a Livewire-only entry
| point, so each step is a shareable URL and the back button behaves.
*/

Route::get('/', HomeController::class)->name('home');
Route::get('/search', SearchController::class)->name('search');

// Where the operator trades from. Branches have been in the schema since Phase
// 1 and, until now, reached a customer only as options in a dropdown — so
// somebody deciding whether to hire here at all could not see the premises,
// find the address or telephone anyone.
Route::get('/branches', LocationsController::class)->name('locations');

// Spec §6 requires the security deposit to appear in the terms and conditions,
// and §10 the insurance excess. There were no terms at all until 2026-08-19 —
// a spec gap rather than a matter of polish. Every figure is read from the same
// source the customer is charged from, so the page cannot drift from checkout.
Route::get('/terms', TermsController::class)->name('terms');

// Browsing a class. Deliberately dateless and therefore quotes nothing — see
// the controller. Registered before the vehicle route only for readability;
// the two prefixes cannot collide.
Route::get('/classes/{slug}', VehicleClassController::class)->name('classes.show');

Route::get('/vehicles/{vehicle}', VehicleController::class)->name('vehicles.show');

// Reserving. Still claims nothing — only BookingCreationService takes a hold.
Route::post('/basket', [BasketController::class, 'store'])->name('basket.store');

// Spec §1.3: the first and only place contact details are asked for.
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

// Reachable by reference so a customer can return to their instructions from
// the confirmation email without an account. Spec §1.4 keeps guests guests.
Route::get('/bookings/{reference}', [CheckoutController::class, 'confirmation'])->name('booking.confirmation');
