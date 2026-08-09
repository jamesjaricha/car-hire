<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Vehicle;
use Illuminate\Contracts\View\View;

/**
 * The shop window.
 *
 * Thin, as ARCHITECTURE §9 requires: it fetches the branches the search form
 * needs and renders. Everything a search actually does lives in
 * `AvailabilityService` and `QuoteService`.
 */
final class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('home', [
            // Only branches a customer could actually collect from. An operator
            // who has closed a branch should not still be offered it.
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
            // A taste of the fleet, one vehicle per class so the same Corolla
            // is not shown four times. Not an availability promise — these
            // carry no dates, so they link into a search rather than a booking.
            //
            // Classes still awaiting a §15 pricing decision are excluded: they
            // cannot be sold, and PricingService refuses to quote them.
            'featuredVehicles' => Vehicle::query()
                ->with('vehicleClass')
                ->bookable()
                ->whereHas('vehicleClass', fn ($query) => $query->active()->fullyPriced())
                ->get()
                ->unique('vehicle_class_id')
                ->take(4)
                ->values(),
        ]);
    }
}
