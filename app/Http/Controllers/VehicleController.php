<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\AvailabilityServiceContract;
use App\Contracts\QuoteServiceContract;
use App\DataTransferObjects\DateRange;
use App\Exceptions\InvalidDateRangeException;
use App\Models\Branch;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One vehicle, priced for the customer's dates.
 *
 * WHY AVAILABILITY IS RE-CHECKED HERE
 *
 * A search result is advisory — ARCHITECTURE §1 is emphatic about it. Between
 * the results page and this one, somebody else may have taken the last Hilux.
 * Showing a full price and a Reserve button for a vehicle that has just gone
 * wastes the customer's time and then fails at the least forgiving moment, so
 * it is checked again before the page renders.
 *
 * It is still not a reservation. Only `VehicleHoldService::place()` decides
 * that, and it does so inside a lock at checkout.
 */
final class VehicleController extends Controller
{
    public function __construct(
        private readonly AvailabilityServiceContract $availability,
        private readonly QuoteServiceContract $quotes,
    ) {}

    public function __invoke(Request $request, Vehicle $vehicle): View
    {
        $data = $request->validate([
            'branch' => ['required', 'integer', 'exists:branches,id'],
            'pickup' => ['required', 'date'],
            'dropoff' => ['required', 'date'],
        ]);

        $branch = Branch::query()->findOrFail($data['branch']);

        try {
            $range = $this->rangeFrom((string) $data['pickup'], (string) $data['dropoff']);
        } catch (InvalidDateRangeException) {
            // The customer's words, not the exception's. See SearchController.
            throw ValidationException::withMessages([
                'dates' => 'Your return date needs to be after your pick-up date.',
            ]);
        }

        // Collecting from a branch this vehicle is not at is not a page that
        // should exist — a hand-altered URL should not produce a quote the
        // operator cannot honour.
        if ((int) $vehicle->branch_id !== (int) $branch->getKey()) {
            throw new NotFoundHttpException;
        }

        $vehicle->loadMissing('vehicleClass');

        return view('vehicle', [
            'vehicle' => $vehicle,
            'class' => $vehicle->vehicleClass,
            'branch' => $branch,
            'range' => $range,
            'quote' => $this->quotes->quoteFor($vehicle, $range),
            // Advisory, and labelled as such on the page.
            'stillAvailable' => $this->availability->isVehicleAvailable($vehicle, $range),
            'pickupInput' => (string) $data['pickup'],
            'dropoffInput' => (string) $data['dropoff'],
        ]);
    }

    private function rangeFrom(string $pickup, string $dropoff): DateRange
    {
        $zone = (string) config('carhire.display_timezone', 'Africa/Lusaka');

        return DateRange::of(
            CarbonImmutable::parse($pickup, $zone)->utc(),
            CarbonImmutable::parse($dropoff, $zone)->utc(),
        );
    }
}
