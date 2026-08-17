@extends('layouts.site')

@section('title', $class->name.' hire')
@section('description', 'Every '.$class->name.' in our fleet. Prices include the mandatory damage waiver, and the refundable deposit is shown before you pay.')

@php
    $images = $class->imagePaths();
    $money = fn (string|null $amount): string => 'ZMW '.number_format((float) ($amount ?? '0'), 2);
@endphp

@section('content')

    {{-- ── The class ─────────────────────────────────────────────────────────
         A browse page, not a quote. It carries no dates, so it names a daily
         rate and never a total — spec §1.2 governs the all-in price and that
         only exists once somebody has chosen days. --}}
    <section class="border-b border-ink-200 bg-brand-950">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-12">
            <a href="{{ route('home') }}"
               class="inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-brand-200 transition-colors duration-150 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd"/>
                </svg>
                All vehicle types
            </a>

            <div class="mt-4 flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-2xl">
                    <h1 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        {{ $class->name }}
                    </h1>
                    @if ($class->description)
                        <p class="mt-3 text-lg leading-relaxed text-brand-100">{{ $class->description }}</p>
                    @endif
                </div>

                @if ($fromDailyRate !== null)
                    <div class="rounded-2xl bg-white/10 px-5 py-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-brand-200">From</p>
                        <p class="tabular mt-1 font-display text-2xl font-semibold text-white">
                            {{ $money($fromDailyRate) }}
                        </p>
                        <p class="text-sm text-brand-200">per day</p>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Check real dates ──────────────────────────────────────────────────
         The only place on this page a real price can come from. Everything
         above is a rate; a hire needs days. --}}
    <section class="border-b border-ink-200 bg-ink-50">
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
            <p class="mb-3 text-sm font-medium text-ink-700">
                Choose your branch and dates to see what is free and what it costs.
            </p>
            <x-search-form :branches="$branches"
                           :pickup-at="$defaultPickup"
                           :dropoff-at="$defaultDropoff"
                           compact />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">

        {{-- Spec §6 and §10, stated once rather than on every card. The deposit
             must never first appear at the counter, and this is one of the
             earliest pages it can appear on. --}}
        <div class="grid gap-3 sm:grid-cols-2">
            <p class="flex items-start gap-2 rounded-xl bg-veld-50 p-3 text-sm text-veld-900">
                <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/>
                </svg>
                <span>
                    A refundable security deposit of
                    <strong class="tabular">{{ $money($class->security_deposit_amount) }}</strong>
                    is taken in cash at the branch and returned when you bring the vehicle back.
                    It is not part of the hire price.
                </span>
            </p>

            <p class="flex items-start gap-2 rounded-xl bg-brand-50 p-3 text-sm text-brand-900">
                <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <span>
                    Every price includes the mandatory damage waiver. If the vehicle is damaged you
                    remain liable for up to
                    <strong class="tabular">{{ $money($class->insurance_excess_amount) }}</strong>.
                </span>
            </p>
        </div>

        {{-- ── Photographs ──────────────────────────────────────────────────── --}}
        @if ($images !== [])
            <ul class="mt-8 grid gap-3 sm:grid-cols-3">
                @foreach (array_slice($images, 0, 3) as $path)
                    <li class="overflow-hidden rounded-2xl border border-ink-200">
                        <img src="{{ Storage::disk('public')->url($path) }}"
                             alt="{{ $class->name }}"
                             loading="lazy" width="480" height="320"
                             class="aspect-[3/2] w-full object-cover">
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- ── The cars ─────────────────────────────────────────────────────── --}}
        <div class="mt-10 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-2xl font-semibold tracking-tight text-ink-900">
                {{ $vehicles->count() }} {{ Str::plural('vehicle', $vehicles->count()) }} in this range
            </h2>
            {{-- Honest about what this list is. Availability needs dates, and
                 this page has none — so it shows the whole range rather than
                 hiding cars that may well be free on the days somebody wants. --}}
            <p class="text-sm text-ink-500">
                Availability depends on your dates — check above.
            </p>
        </div>

        @if ($vehicles->isEmpty())
            <div class="mt-6 rounded-2xl border border-dashed border-ink-300 bg-ink-50 p-10 text-center">
                <h3 class="font-display text-lg font-semibold text-ink-900">
                    Nothing in this range at the moment
                </h3>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-600">
                    Every vehicle in this class is currently off the road. Try another vehicle
                    type, or search your dates to see what else is free.
                </p>
            </div>
        @else
            <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($vehicles as $vehicle)
                    <li>
                        {{-- Every card leads to the product page.

                             The vehicle page validates branch, pickup and
                             dropoff as required and 404s without them, so the
                             link has to carry all three. The dates are this
                             page's defaults — the same ones the form above
                             shows, so a customer clicking through sees the days
                             they were already looking at rather than different
                             ones.

                             The branch is the VEHICLE'S own, not a chosen one:
                             the vehicle page 404s when the branch in the URL is
                             not where the car actually is, which is what stops
                             a hand-altered link producing a quote the operator
                             cannot honour.

                             This page still quotes nothing itself. The price
                             appears on arrival, computed by QuoteService from
                             real dates, exactly as it is from search. --}}
                        <a href="{{ route('vehicles.show', [
                               'vehicle' => $vehicle->id,
                               'branch' => $vehicle->branch_id,
                               'pickup' => $defaultPickup,
                               'dropoff' => $defaultDropoff,
                           ]) }}"
                           class="rise liftable group flex h-full flex-col overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm [transition:transform_200ms_var(--ease-out-strong),box-shadow_200ms_var(--ease-out-strong),border-color_200ms_ease] hover:border-brand-200 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">

                            {{-- THIS car's photograph, which is the entire point
                                 of this list. Somebody choosing between four
                                 Corollas is choosing on colour, age and
                                 condition, and until now this grid showed them
                                 four identical blocks of text. Falls back to
                                 the class gallery, then the silhouette. --}}
                            <div class="relative aspect-[16/10] overflow-hidden bg-ink-100">
                                <x-vehicle-image :path="$vehicle->primaryImagePath()"
                                                 :alt="$vehicle->hasOwnImages() ? $vehicle->displayName() : $class->name"
                                                 width="480" height="300"
                                                 imgClass="absolute inset-0 size-full object-cover [transition:transform_300ms_var(--ease-out-strong)] group-hover:scale-105"
                                                 panelClass="absolute inset-0"
                                                 glyphClass="w-2/5" />
                            </div>

                            <div class="flex flex-1 flex-col p-5">
                                <p class="font-display text-lg font-semibold text-ink-900">
                                    {{ $vehicle->make }} {{ $vehicle->model }}
                                </p>
                                <p class="mt-0.5 text-sm text-ink-500">
                                    {{ $vehicle->year ? $vehicle->year.' · ' : '' }}{{ $vehicle->branch?->name }}
                                </p>

                                <ul class="mt-3 flex flex-wrap gap-1.5 text-xs font-medium text-ink-700">
                                    @if ($vehicle->seats)
                                        <li class="rounded-lg bg-ink-50 px-2 py-1">{{ $vehicle->seats }} seats</li>
                                    @endif
                                    @if ($vehicle->transmission)
                                        <li class="rounded-lg bg-ink-50 px-2 py-1">{{ ucfirst((string) $vehicle->transmission) }}</li>
                                    @endif
                                    @if ($vehicle->fuel_type)
                                        <li class="rounded-lg bg-ink-50 px-2 py-1">{{ ucfirst((string) $vehicle->fuel_type) }}</li>
                                    @endif
                                </ul>

                                {{-- A visible affordance rather than only the
                                     hover lift, which does not exist on a touch
                                     screen. --}}
                                <p class="mt-auto flex items-center gap-1.5 pt-4 text-sm font-semibold text-brand-600">
                                    See price and book
                                    <svg aria-hidden="true" class="size-4 [transition:transform_200ms_var(--ease-out-strong)] group-hover:translate-x-1" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>
                                    </svg>
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

@endsection
