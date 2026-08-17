<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\PricingServiceContract;
use App\Models\Branch;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One vehicle class, and every car in it.
 *
 * WHY THIS PAGE QUOTES NOTHING
 *
 * It has no dates, and without dates there is no hire to price. Spec §1.2
 * requires the all-in price to be identical from search through to checkout,
 * and `SearchController` prices every vehicle individually rather than showing
 * a cheaper "from" figure precisely so those cannot drift.
 *
 * So this page does not compute a quote at all. What it shows is the lowest
 * DAILY RATE any car in the class carries, labelled as a daily rate and never
 * as a total. That is not a quote and cannot disagree with one: it is the same
 * `PricingService::dailyRateFor()` figure the quote is built from, and the
 * booking journey still runs through search, where real dates produce real
 * prices.
 *
 * The lowest rate is taken across the vehicles rather than from the class,
 * because a vehicle-level override can be lower OR higher than its class. Using
 * `vehicle_classes.daily_rate` would advertise a rate that no actual car in the
 * class charges, which is the drift this design exists to avoid.
 *
 * WHY IT SHOWS EVERY CAR RATHER THAN THE AVAILABLE ONES
 *
 * This is a browse page. Filtering by availability needs dates, and dates the
 * customer never chose would hide cars that are free on the days they actually
 * want — showing fewer options than the operator has, which is the fault this
 * page was built to fix. Vehicles retired or off the road are excluded, because
 * those are not options at all, and the page says plainly that availability
 * depends on dates.
 */
final class VehicleClassController extends Controller
{
    public function __construct(
        private readonly PricingServiceContract $pricing,
    ) {}

    public function __invoke(string $slug): View
    {
        // Resolved by slug rather than id because this is a customer-facing,
        // shareable URL.
        //
        // ⚠ `vehicle_classes` is unique on (operator_id, slug), NOT on slug
        // alone, so this is correct only while the platform serves one
        // operator. That is true at MVP and is recorded in OPEN-ITEMS: opening
        // the platform to other operators needs an operator context on every
        // public route — a domain or a path segment — and this lookup becomes
        // ambiguous at exactly that moment, not before.
        $class = VehicleClass::query()
            ->active()
            // A class nobody has priced cannot be sold, so it must not have a
            // shop window either. Same rule AvailabilityService applies to
            // search results, enforced here for the page that bypasses it.
            ->fullyPriced()
            ->where('slug', $slug)
            ->first();

        if ($class === null) {
            throw new NotFoundHttpException;
        }

        // `vehicleClass` is eager-loaded for the image fallback, not for the
        // page's own data — every card resolves its photograph through
        // `Vehicle::imagePaths()`, which reaches back to the class when the car
        // has none of its own. `Model::shouldBeStrict()` turns a missed
        // eager-load into an exception outside production rather than a silent
        // N+1, so this line is load-bearing on the class page in particular:
        // it is the one screen that renders every vehicle in a class at once.
        $vehicles = Vehicle::query()
            ->with(['branch', 'vehicleClass'])
            ->bookable()
            ->where('vehicle_class_id', $class->getKey())
            ->orderBy('registration')
            ->get();

        return view('vehicle-class', [
            'class' => $class,
            'vehicles' => $vehicles,
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
            'fromDailyRate' => $this->pricing->lowestDailyRate($class, $vehicles),
            // The same defaults the home page and the search form use, so a
            // customer arriving here sees dates consistent with wherever they
            // came from.
            'defaultPickup' => $this->defaultAt(1),
            'defaultDropoff' => $this->defaultAt(4),
        ]);
    }

    private function defaultAt(int $daysAhead): string
    {
        $zone = (string) config('carhire.display_timezone', 'Africa/Lusaka');

        return CarbonImmutable::now($zone)
            ->addDays($daysAhead)
            ->setTime(9, 0)
            ->format('Y-m-d\TH:i');
    }
}
