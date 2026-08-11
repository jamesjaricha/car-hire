<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\AvailabilityServiceContract;
use App\Contracts\BasketServiceContract;
use App\Contracts\QuoteServiceContract;
use App\DataTransferObjects\Basket;
use App\DataTransferObjects\DateRange;
use App\DataTransferObjects\QuoteOptions;
use App\Exceptions\InvalidDateRangeException;
use App\Models\Branch;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Putting a vehicle in the basket. Spec §1.1.
 *
 * THE PRICE IS FROZEN HERE AND NOWHERE ELSE
 *
 * The quote computed at this moment is what the customer pays, for the life of
 * the basket. Checkout reads it back rather than recomputing, because
 * recomputing would silently honour a rate change while somebody is halfway
 * through paying — precisely what §1.2 forbids.
 *
 * STILL NOT A RESERVATION
 *
 * A basket claims nothing. The vehicle is only held when the booking is created,
 * inside `VehicleHoldService::place()` and its row lock. Availability is checked
 * here because offering a checkout for a vehicle that has just gone wastes the
 * customer's time, but a green answer here is advisory — ARCHITECTURE §1.
 */
final class BasketController extends Controller
{
    public function __construct(
        private readonly AvailabilityServiceContract $availability,
        private readonly QuoteServiceContract $quotes,
        private readonly BasketServiceContract $baskets,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vehicle' => ['required', 'integer', 'exists:vehicles,id'],
            'branch' => ['required', 'integer', 'exists:branches,id'],
            'pickup' => ['required', 'date'],
            'dropoff' => ['required', 'date'],
        ]);

        $vehicle = Vehicle::query()->with('vehicleClass')->findOrFail($data['vehicle']);
        $branch = Branch::query()->findOrFail($data['branch']);

        try {
            $range = $this->rangeFrom((string) $data['pickup'], (string) $data['dropoff']);
        } catch (InvalidDateRangeException) {
            // The customer's words, not the exception's. See SearchController.
            throw ValidationException::withMessages([
                'dates' => 'Your return date needs to be after your pick-up date.',
            ]);
        }

        if (! $this->availability->isVehicleAvailable($vehicle, $range)) {
            // Back to the results with an explanation rather than into a
            // checkout that cannot complete.
            return redirect()
                ->route('search', [
                    'branch' => $branch->getKey(),
                    'pickup' => $data['pickup'],
                    'dropoff' => $data['dropoff'],
                ])
                ->with('notice', 'That vehicle has just been taken for those dates. Here is what else is free.');
        }

        $options = QuoteOptions::none();

        $this->baskets->place(Basket::start(
            vehicleId: (int) $vehicle->getKey(),
            pickupBranchId: (int) $branch->getKey(),
            // One-way hires are by staff arrangement only, so a customer always
            // returns the vehicle where they collected it.
            dropoffBranchId: (int) $branch->getKey(),
            range: $range,
            options: $options,
            quote: $this->quotes->quoteFor($vehicle, $range, $options),
        ));

        return redirect()->route('checkout');
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
