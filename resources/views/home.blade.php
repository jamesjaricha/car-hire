@extends('layouts.site')

@section('title', 'Hire a car in Zambia')

@section('content')

    {{-- ── Search ────────────────────────────────────────────────────────────
         The search form is the main interaction point. The hero section 
         uses a high-quality glassmorphism layout to feel premium and 
         trustworthy. --}}
    <section class="relative flex min-h-[500px] items-center overflow-hidden border-b border-ink-200 bg-ink-950">
        {{-- High quality hero background --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <img src="{{ asset('images/hero_image_zambia.png') }}" alt="" class="h-full w-full object-cover object-center opacity-80" />
            <div class="absolute inset-0 bg-gradient-to-t from-ink-950/90 via-ink-900/40 to-transparent"></div>
        </div>

        <div class="relative mx-auto w-full max-w-5xl px-4 pb-12 pt-16 sm:px-6 sm:pb-20 sm:pt-24 lg:pb-24 lg:pt-32">
            <div class="flex flex-col items-center text-center gap-10">
                <div class="max-w-3xl">
                    <h1 class="font-display text-4xl font-bold leading-tight tracking-tight text-white drop-shadow-md sm:text-5xl lg:text-6xl">
                        Hire a car in Zambia
                    </h1>
                    <p class="mt-4 text-lg leading-relaxed text-ink-100 drop-shadow-md sm:text-xl">
                        Experience the ultimate freedom of exploring Zambia with our premium fleet.
                    </p>
                </div>

                <div class="w-full max-w-5xl">
                    <x-search-form :branches="$branches" />
                </div>
            </div>
        </div>
    </section>

<div class="bg-ink-50/50 pb-16">

    {{-- ── Featured Vehicles ────────────────────────────────────────────────── --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24 border-b border-ink-200">
        <div class="text-center md:text-left">
            <h2 class="font-display text-3xl font-bold tracking-tight text-ink-900 sm:text-4xl">
                Our Fleet
            </h2>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-ink-600 md:mx-0">
                A selection of our premium vehicles available for your next adventure.
            </p>
        </div>

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($featuredVehicles as $vehicle)
                {{-- `transition-all` replaced with the two properties that
                     actually change. `all` drags every animatable property
                     through the same curve and costs paint work per frame for
                     changes nobody asked for. The lift moved to `liftable`,
                     which is gated behind a fine-pointer query — on a touch
                     device hover fires on tap and leaves the card stuck in its
                     raised state after the finger lifts. --}}
                <div class="rise liftable group flex flex-col overflow-hidden rounded-3xl border border-ink-200 bg-white shadow-sm [transition:transform_200ms_var(--ease-out-strong),box-shadow_200ms_var(--ease-out-strong),border-color_200ms_ease] hover:border-brand-200 hover:shadow-lg">
                    @php $classImage = $vehicle->vehicleClass?->primaryImagePath(); @endphp
                    <div class="aspect-[4/3] bg-ink-100 relative overflow-hidden">
                        @if ($classImage)
                            {{-- The photograph the operator uploaded against this
                                 class, in the admin panel. --}}
                            <img src="{{ Storage::disk('public')->url($classImage) }}"
                                 alt="{{ $vehicle->vehicleClass?->name }}"
                                 loading="lazy" width="480" height="360"
                                 class="absolute inset-0 size-full object-cover [transition:transform_300ms_var(--ease-out-strong)] group-hover:scale-105">
                        @else
                            {{-- No photograph uploaded yet. Deliberately typographic
                                 rather than a stock car, so nobody mistakes it for
                                 the vehicle they are hiring. --}}
                            <div class="absolute inset-0 flex items-center justify-center p-6 text-center text-ink-400 font-display text-2xl font-bold group-hover:scale-105 [transition:transform_300ms_var(--ease-out-strong)]">
                                {{ $vehicle->make }}<br>{{ $vehicle->model }}
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col justify-between p-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-600">{{ $vehicle->vehicleClass?->name ?? 'Vehicle' }}</p>
                            <h3 class="mt-2 font-display text-lg font-bold text-ink-900">{{ $vehicle->make }} {{ $vehicle->model }}</h3>
                            <ul class="mt-4 flex flex-wrap gap-2 text-xs font-medium text-ink-700">
                                <li class="rounded-lg bg-ink-50 px-2.5 py-1">{{ $vehicle->seats }} seats</li>
                                <li class="rounded-lg bg-ink-50 px-2.5 py-1">{{ ucfirst((string) $vehicle->transmission) }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── How it works ──────────────────────────────────────────────────────
         This section exists because of how the operator takes money. There is
         no card gateway: a customer transfers to an account number and waits
         for a person to verify it. Left unexplained that feels like sending
         money into the dark, so the sequence and the timings are stated plainly
         before anybody is asked to commit. --}}
    <section id="how-it-works" class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="rounded-3xl bg-brand-950 p-8 shadow-lg md:px-12 md:py-10 text-center md:text-left">
            <h2 class="font-display text-3xl font-bold tracking-tight text-white sm:text-4xl">
                How booking works
            </h2>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-brand-100 md:mx-0">
                Your vehicle is held for you while you pay. Nothing is charged automatically —
                a member of staff checks every payment.
            </p>
        </div>

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['1', 'Choose your vehicle', 'Search your dates and branch. Every price shown includes insurance.'],
                ['2', 'Reserve it', 'We hold that specific vehicle for you and give you a payment reference.'],
                ['3', 'Make payment', 'Pay securely by transfer, mobile money or cash before the deadline.'],
                ['4', 'We confirm it', 'A member of staff verifies your payment and confirms your booking.'],
            ] as [$step, $heading, $body])
                <div class="rise liftable group relative flex flex-col justify-between rounded-3xl border border-ink-100 bg-white p-6 shadow-sm [transition:transform_200ms_var(--ease-out-strong),box-shadow_200ms_var(--ease-out-strong),border-color_200ms_ease] hover:border-brand-200 hover:shadow-lg">
                    <div>
                        <span aria-hidden="true"
                              class="flex size-12 items-center justify-center rounded-2xl bg-ink-50 font-display text-lg font-bold text-ink-700 shadow-sm transition-colors duration-300 group-hover:bg-brand-50 group-hover:text-brand-700">
                            {{ $step }}
                        </span>
                        <h3 class="mt-6 font-display text-xl font-semibold text-ink-900">{{ $heading }}</h3>
                        <p class="mt-3 text-sm leading-relaxed text-ink-600">{{ $body }}</p>
                    </div>
                    {{-- Was animating `width`, which forces layout and paint on
                         every frame. `scaleX` from a left origin looks identical
                         and runs on the compositor. --}}
                    <div class="mt-8 h-1 w-full origin-left scale-x-[0.25] rounded-full bg-ink-100 [transition:transform_240ms_var(--ease-out-strong),background-color_240ms_ease] group-hover:scale-x-100 group-hover:bg-brand-500"></div>
                </div>
            @endforeach
        </div>

        {{-- The two deposits are the single most likely misreading in the whole
             specification, so they are separated here in the customer's own
             words rather than left to the checkout page. --}}
        <div class="mt-16 grid gap-6 sm:grid-cols-2">
            <div class="group relative overflow-hidden rounded-3xl border border-ink-200 bg-gradient-to-br from-ink-50 to-white p-8 [transition:box-shadow_240ms_var(--ease-out-strong)] hover:shadow-md">
                <div class="absolute -mr-12 -mt-12 right-0 top-0 size-40 rounded-full bg-ink-100/50 blur-3xl [transition:background-color_300ms_ease] group-hover:bg-brand-100/50"></div>
                <div class="relative">
                    <div class="flex items-center gap-4">
                        <div class="flex size-10 items-center justify-center rounded-full bg-white text-ink-700 shadow-sm ring-1 ring-ink-200 transition-colors group-hover:text-brand-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>
                        <h3 class="font-display text-lg font-semibold text-ink-900">
                            The deposit you pay online
                        </h3>
                    </div>
                    <p class="mt-4 text-base leading-relaxed text-ink-600">
                        Part of the hire cost. You can pay it in full instead if you prefer —
                        either way it comes off what you owe.
                    </p>
                </div>
            </div>
            
            <div class="group relative overflow-hidden rounded-3xl border border-veld-200 bg-gradient-to-br from-veld-50 to-white p-8 [transition:box-shadow_240ms_var(--ease-out-strong)] hover:shadow-md">
                <div class="absolute -mr-12 -mt-12 right-0 top-0 size-40 rounded-full bg-veld-100/50 blur-3xl [transition:background-color_300ms_ease] group-hover:bg-veld-200/50"></div>
                <div class="relative">
                    <div class="flex items-center gap-4">
                        <div class="flex size-10 items-center justify-center rounded-full bg-white text-veld-700 shadow-sm ring-1 ring-veld-200 transition-colors group-hover:text-veld-800">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <h3 class="font-display text-lg font-semibold text-veld-900">
                            The refundable security deposit
                        </h3>
                    </div>
                    <p class="mt-4 text-base leading-relaxed text-veld-800">
                        Cash held at the branch against damage, and returned to you when you
                        bring the vehicle back. This is not part of the hire price.
                    </p>
                </div>
            </div>
        </div>
    </section>

</div>

@endsection
