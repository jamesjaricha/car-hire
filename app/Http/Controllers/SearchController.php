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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Search results. Spec §1.1: no personal details, ever, to get this far.
 *
 * TIMEZONE HANDLING IS THE SUBTLE PART
 *
 * The two datetime inputs are wall-clock times in Lusaka, because that is where
 * the customer and the branch both are. Everything downstream stores and
 * compares UTC. The conversion happens here, at the edge, exactly as
 * ARCHITECTURE §5 requires — a booking made at 23:30 on the 6th is 21:30 UTC on
 * the 6th, and getting that wrong moves somebody's pickup by two hours.
 *
 * WHY EVERY VEHICLE IS PRICED INDIVIDUALLY
 *
 * `QuoteService` is the single implementation of what a hire costs, and spec
 * §1.2 requires the price here to equal the price at checkout. Computing a
 * cheaper "from" price on this page and a real one later is precisely how those
 * two drift apart.
 */
final class SearchController extends Controller
{
    public function __construct(
        private readonly AvailabilityServiceContract $availability,
        private readonly QuoteServiceContract $quotes,
    ) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'branch' => ['required', 'integer', 'exists:branches,id'],
            'pickup' => ['required', 'date'],
            'dropoff' => ['required', 'date'],
        ]);

        $branch = Branch::query()->findOrFail($data['branch']);

        try {
            $range = $this->rangeFrom((string) $data['pickup'], (string) $data['dropoff']);
        } catch (InvalidDateRangeException $e) {
            // Back to the form with the reason, rather than an error page. A
            // customer who typed the dates the wrong way round has made an
            // ordinary mistake.
            throw ValidationException::withMessages(['dates' => $e->getMessage()]);
        }

        $vehicles = $this->availability->availableVehicles($branch, $range);

        // Priced individually, then grouped so the customer chooses a class
        // rather than scrolling past four identical Corollas. The cheapest
        // vehicle in each class represents it.
        $results = $vehicles
            ->map(fn (Vehicle $vehicle): array => [
                'vehicle' => $vehicle,
                'quote' => $this->quotes->quoteFor($vehicle, $range),
            ])
            ->sortBy(fn (array $result): string => $result['quote']->grandTotal)
            ->groupBy(fn (array $result): int => $result['vehicle']->vehicle_class_id)
            ->map(fn ($group) => [
                'cheapest' => $group->first(),
                'available' => $group->count(),
            ])
            ->values();

        return view('search', [
            'branch' => $branch,
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
            'range' => $range,
            'results' => $results,
            'pickupInput' => (string) $data['pickup'],
            'dropoffInput' => (string) $data['dropoff'],
        ]);
    }

    /**
     * Wall-clock Lusaka in, UTC out.
     */
    private function rangeFrom(string $pickup, string $dropoff): DateRange
    {
        $zone = (string) config('carhire.display_timezone', 'Africa/Lusaka');

        return DateRange::of(
            CarbonImmutable::parse($pickup, $zone)->utc(),
            CarbonImmutable::parse($dropoff, $zone)->utc(),
        );
    }
}
