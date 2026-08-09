@extends('layouts.site')

@section('title', 'Available vehicles')

@php
    $zone = config('carhire.display_timezone');
    $days = $range->chargeableDays();
@endphp

@section('content')

    {{-- The search stays on the page, prefilled. Changing dates is the most
         common thing anybody does on a results page, and sending them back to
         the home page to do it loses the results they were comparing. --}}
    <section class="border-b border-ink-200 bg-brand-950">
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
            <x-search-form :branches="$branches"
                           :branch-id="$branch->id"
                           :pickup-at="$pickupInput"
                           :dropoff-at="$dropoffInput"
                           compact />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">

        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="font-display text-2xl font-semibold tracking-tight text-ink-900">
                {{ $results->count() }} {{ Str::plural('vehicle type', $results->count()) }} available
            </h1>
            <p class="tabular text-sm text-ink-600">
                {{ $branch->name }} &middot;
                {{ $range->start->setTimezone($zone)->format('j M, H:i') }}
                &rarr;
                {{ $range->end->setTimezone($zone)->format('j M, H:i') }}
                &middot; {{ $days }} {{ Str::plural('day', $days) }}
            </p>
        </div>

        {{-- Spec §1.2 and §6, stated once at the top so it is not repeated on
             every card: the price includes insurance, and the refundable
             deposit is additional. §6 says the deposit must never first appear
             at the counter — this is the earliest place it can appear. --}}
        <p class="mt-3 flex items-start gap-2 rounded-xl bg-brand-50 p-3 text-sm text-brand-900">
            <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <span>
                Every price below is for the whole hire and already includes the mandatory
                damage waiver. A refundable security deposit is taken in cash at the branch
                and returned when you bring the vehicle back — it is shown on each vehicle.
            </span>
        </p>

        @if ($results->isEmpty())
            {{-- An empty result is a dead end unless it says what to change. --}}
            <div class="mt-10 rounded-2xl border border-dashed border-ink-300 bg-ink-50 p-10 text-center">
                <h2 class="font-display text-lg font-semibold text-ink-900">
                    Nothing available for those dates
                </h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-600">
                    Every vehicle at {{ $branch->name }} is already booked for that window,
                    or is being prepared between hires. Try shifting your dates by a day, or
                    collecting from another branch.
                </p>
            </div>
        @else
            <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($results as $result)
                    <x-vehicle-card :vehicle="$result['cheapest']['vehicle']"
                                    :quote="$result['cheapest']['quote']"
                                    :available="$result['available']"
                                    :branch-id="$branch->id"
                                    :pickup-input="$pickupInput"
                                    :dropoff-input="$dropoffInput" />
                @endforeach
            </div>
        @endif
    </section>

@endsection
