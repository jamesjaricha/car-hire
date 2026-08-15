<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\PricingServiceContract;
use App\Models\Branch;
use App\Models\VehicleClass;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * The shop window.
 *
 * Thin, as ARCHITECTURE §9 requires: it fetches the branches the search form
 * needs and renders. Everything a search actually does lives in
 * `AvailabilityService` and `QuoteService`.
 *
 * WHY THE DEFAULT DATES ARE COMPUTED HERE RATHER THAN IN THE VIEW
 *
 * Two things on this page need them: the search form's prefilled inputs, and
 * the fleet cards, which link to a vehicle page and must name the dates they
 * are quoting for. `x-search-form` can compute its own when nothing is passed —
 * that is what every other page relies on — but if it did so here the card
 * links and the form beside them could name different days. A customer who
 * clicks a card expecting the dates they can see in the form would land on a
 * quote for different ones, and the price would not match what they were
 * looking at. Computed once, passed to both.
 */
final class HomeController extends Controller
{
    public function __construct(
        private readonly PricingServiceContract $pricing,
    ) {}

    public function __invoke(): View
    {
        // Wall-clock Lusaka, in the format the datetime-local inputs and the
        // search route both expect. ARCHITECTURE §5: conversion to UTC happens
        // at the edge that receives these, not here.
        $zone = (string) config('carhire.display_timezone', 'Africa/Lusaka');
        $today = CarbonImmutable::now($zone);

        return view('home', [
            'defaultPickup' => $today->addDay()->setTime(9, 0)->format('Y-m-d\TH:i'),
            'defaultDropoff' => $today->addDays(4)->setTime(9, 0)->format('Y-m-d\TH:i'),
            // Only branches a customer could actually collect from. An operator
            // who has closed a branch should not still be offered it.
            'branches' => Branch::query()->active()->orderBy('name')->get(['id', 'name']),
            // THE FLEET, BY CLASS — NOT BY REPRESENTATIVE VEHICLE
            //
            // This used to select vehicles and show one per class, which meant
            // a single Corolla stood for the whole Economy range. A customer
            // reading that sees one car where the operator has four, and the
            // choice of which car appeared was whichever row the database
            // returned first — not a decision anybody made.
            //
            // So the cards are classes now. Each links to a class page listing
            // every car in it. No dates are carried, because that page quotes
            // nothing: see VehicleClassController for why that is deliberate
            // rather than a shortcut.
            //
            // There was also a `take(4)` here, capping the section at one row.
            // It made an eighteen-vehicle fleet look like a four-car one, which
            // is the opposite of what this section is for. If the fleet grows
            // past a dozen classes this wants paging, but hiding two thirds of
            // it is not the answer.
            //
            // Classes still awaiting a §15 pricing decision are excluded: they
            // cannot be sold, and PricingService refuses to quote them.
            'classes' => $this->bookableClasses(),
        ]);
    }

    /**
     * Sellable classes, each with the size of its fleet and its lowest rate.
     *
     * The vehicle relation is constrained to bookable ones, so the count on the
     * card is the number a customer could actually hire — counting a retired
     * car would overstate the fleet on the shop window.
     *
     * A class holding no bookable vehicle is dropped entirely. It is sellable on
     * paper with nothing behind it, so a card for it would lead to an empty page.
     *
     * Returned as arrays rather than models carrying invented attributes,
     * matching how SearchController hands its grouped results to a view.
     *
     * @return Collection<int, array{class: VehicleClass, vehicleCount: int, fromDailyRate: string|null}>
     */
    private function bookableClasses(): Collection
    {
        return VehicleClass::query()
            ->active()
            ->fullyPriced()
            ->with(['vehicles' => fn ($query) => $query->bookable()])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (VehicleClass $class): bool => $class->vehicles->isNotEmpty())
            ->map(fn (VehicleClass $class): array => [
                'class' => $class,
                'vehicleCount' => $class->vehicles->count(),
                // One implementation of the override chain, in PricingService.
                'fromDailyRate' => $this->pricing->lowestDailyRate($class, $class->vehicles),
            ])
            ->values();
    }
}
