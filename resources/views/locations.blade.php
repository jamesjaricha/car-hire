@extends('layouts.site')

@section('title', 'Our branches')
@section('description', 'Where to collect and return a hire vehicle. Addresses, telephone numbers and opening hours for every branch.')

@section('content')

    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 sm:py-14">

        <h1 class="font-display text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl">
            Our branches
        </h1>
        <p class="mt-3 max-w-prose leading-relaxed text-ink-600">
            Vehicles are collected and returned in person, so it is worth knowing where
            you are going. One-way hires between branches can be arranged with staff.
        </p>

        @if ($branches->isEmpty())
            {{-- Reachable on a fresh install before anybody has set the platform
                 up. Says what is true rather than rendering an empty grid that
                 reads as a broken page. --}}
            <div class="mt-10 rounded-2xl border border-dashed border-ink-300 bg-ink-50 p-10 text-center">
                <h2 class="font-display text-lg font-semibold text-ink-900">
                    No branches published yet
                </h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-600">
                    Please get in touch before travelling.
                </p>
            </div>
        @else
            <ul class="mt-10 grid gap-5 sm:grid-cols-2">
                @foreach ($branches as $branch)
                    <li class="flex h-full flex-col rounded-2xl border border-ink-200 bg-white p-6 shadow-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <h2 class="font-display text-xl font-semibold text-ink-900">
                                {{ $branch->name }}
                            </h2>
                            {{-- Bookable cars only, matching the home page. A
                                 vehicle off the road is not an option, so
                                 counting it would advertise a fleet this branch
                                 cannot supply. --}}
                            <span class="shrink-0 rounded-lg bg-ink-50 px-2 py-1 text-xs font-medium text-ink-600">
                                {{ $branch->vehicles_count }} {{ Str::plural('vehicle', $branch->vehicles_count) }}
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-ink-500">{{ $branch->city }}</p>

                        <dl class="mt-5 space-y-3 text-sm">
                            @if ($branch->address)
                                <div class="flex items-start gap-2.5">
                                    <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-ink-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 2a6 6 0 00-6 6c0 4.2 5.2 9.4 5.4 9.6a.8.8 0 001.2 0C10.8 17.4 16 12.2 16 8a6 6 0 00-6-6zm0 8.2A2.2 2.2 0 1110 5.8a2.2 2.2 0 010 4.4z" clip-rule="evenodd"/>
                                    </svg>
                                    <div>
                                        <dt class="sr-only">Address</dt>
                                        <dd class="leading-relaxed text-ink-700">{{ $branch->address }}</dd>
                                    </div>
                                </div>
                            @endif

                            @if ($branch->phone_e164)
                                <div class="flex items-start gap-2.5">
                                    <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-ink-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M2 3.5A1.5 1.5 0 013.5 2h1.7a1.5 1.5 0 011.44 1.08l.7 2.4a1.5 1.5 0 01-.76 1.76l-1 .5a11.5 11.5 0 004.68 4.68l.5-1a1.5 1.5 0 011.76-.76l2.4.7A1.5 1.5 0 0118 12.8v1.7a1.5 1.5 0 01-1.5 1.5A14.5 14.5 0 012 3.5z"/>
                                    </svg>
                                    <div>
                                        <dt class="sr-only">Telephone</dt>
                                        {{-- A real tel: link. Most visitors are
                                             on a phone, and a number they can
                                             tap beats one they must copy. --}}
                                        <dd>
                                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $branch->phone_e164) }}"
                                               class="rounded font-medium text-brand-700 underline decoration-brand-300 underline-offset-2 hover:decoration-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                                                {{ $branch->phone_e164 }}
                                            </a>
                                        </dd>
                                    </div>
                                </div>
                            @endif

                            <div class="flex items-start gap-2.5">
                                <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-ink-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-12a.75.75 0 00-1.5 0v4c0 .28.16.54.4.67l2.5 1.4a.75.75 0 10.74-1.3l-2.14-1.2V6z" clip-rule="evenodd"/>
                                </svg>
                                <div>
                                    <dt class="sr-only">Opening hours</dt>
                                    <dd class="text-ink-700">
                                        {{-- Spec §15.8 is the operator's to
                                             answer, and the columns are nullable
                                             so it can go unanswered. Saying so
                                             plainly beats printing a plausible
                                             "08:00–17:00" that has somebody
                                             drive to a closed gate. --}}
                                        @if ($branch->openingHoursLabel())
                                            <span class="tabular">{{ $branch->openingHoursLabel() }}</span>
                                        @else
                                            <span class="text-ink-500">Opening hours not published — please telephone before travelling.</span>
                                        @endif
                                    </dd>
                                </div>
                            </div>

                            @if ($branch->after_hours_pickup)
                                <div class="flex items-start gap-2.5">
                                    <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-veld-600" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0l-3.5-3.5a1 1 0 111.4-1.4l2.8 2.79 6.8-6.79a1 1 0 011.4 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <div>
                                        <dt class="sr-only">After-hours collection</dt>
                                        <dd class="text-ink-700">Collection outside these hours can be arranged.</dd>
                                    </div>
                                </div>
                            @endif
                        </dl>

                        <a href="{{ route('home') }}"
                           class="pressable mt-auto block cursor-pointer rounded-xl bg-brand-600 px-4 pt-3.5 pb-3.5 text-center font-display text-sm font-semibold text-white [transition:transform_160ms_var(--ease-out-strong),background-color_160ms_ease] hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                            Search cars at {{ $branch->name }}
                            <span class="sr-only">— choose your dates on the home page</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Stated here because it is a real constraint a customer plans around,
             and the search form gives no hint of it. Confirmed with the operator
             on 2026-08-03: one-way hires are allowed by arrangement only, and no
             vehicle is relocated automatically. --}}
        <p class="mt-10 max-w-prose text-sm leading-relaxed text-ink-600">
            A vehicle is normally returned to the branch it was collected from. One-way
            hires are possible by arrangement — telephone the collecting branch before
            you book.
        </p>
    </div>

@endsection
