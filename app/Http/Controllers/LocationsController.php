<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Contracts\View\View;

/**
 * Where the operator trades from.
 *
 * WHY THIS PAGE EXISTS
 *
 * Branches have been in the schema since Phase 1 and, until now, appeared to a
 * customer only as options in a dropdown. Somebody deciding whether to hire
 * from this operator at all cannot see that he has two premises, where they
 * are, or how to telephone one — which on a site with no card gateway, where
 * the customer is being asked to transfer money and trust a stranger, is a
 * strange thing to withhold.
 *
 * ONLY ACTIVE BRANCHES
 *
 * `is_active` is the off switch that replaces deletion, and a closed branch is
 * not somewhere anybody can collect a car. It stays in the database because
 * bookings read their collection point through it.
 *
 * VEHICLE COUNTS ARE BOOKABLE ONES
 *
 * Same rule as the home page: a car in maintenance or retired is not an option,
 * so counting it would advertise a fleet the branch cannot supply.
 *
 * NOTHING HERE IS INVENTED. A branch with no published hours says so — see
 * `Branch::openingHoursLabel()` and spec §15.8. The alternative, printing a
 * plausible "08:00–17:00", has somebody drive to a closed gate.
 */
final class LocationsController extends Controller
{
    public function __invoke(): View
    {
        $branches = Branch::query()
            ->active()
            ->withCount(['vehicles' => fn ($query) => $query->bookable()])
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        return view('locations', [
            'branches' => $branches,
        ]);
    }
}
