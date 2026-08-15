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
                    {{-- The dates are passed rather than left to the component's
                         own defaults, so the form and the fleet cards below it
                         cannot name different days. See HomeController. --}}
                    <x-search-form :branches="$branches"
                                   :pickup-at="$defaultPickup"
                                   :dropoff-at="$defaultDropoff" />
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

        {{-- Three columns, not four. Six classes divide into two full rows of
             three; into four they would leave a ragged 4 + 2. Wider cards also
             give the illustration and the specification chips more room. --}}
        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($classes as $entry)
                @php
                    $class = $entry['class'];
                    $classImage = $class->primaryImagePath();
                @endphp
                {{-- `transition-all` replaced with the two properties that
                     actually change. `all` drags every animatable property
                     through the same curve and costs paint work per frame for
                     changes nobody asked for. The lift moved to `liftable`,
                     which is gated behind a fine-pointer query — on a touch
                     device hover fires on tap and leaves the card stuck in its
                     raised state after the finger lifts. --}}
                {{-- The whole card is the link, and it points at the CLASS.

                     It used to point at a single vehicle chosen by whichever row
                     the database returned first, which meant one Corolla stood
                     for the entire Economy range — the operator's four cars
                     looked like one. The class page lists them all.

                     No dates are carried, deliberately: that page quotes
                     nothing, so it has nothing to be consistent with. See
                     VehicleClassController.

                     An <a> rather than a nested button — nothing else in here is
                     interactive, and one large target beats a small one a thumb
                     has to aim for. --}}
                <a href="{{ route('classes.show', ['slug' => $class->slug]) }}"
                   class="rise liftable group flex flex-col overflow-hidden rounded-3xl border border-ink-200 bg-white shadow-sm [transition:transform_200ms_var(--ease-out-strong),box-shadow_200ms_var(--ease-out-strong),border-color_200ms_ease] hover:border-brand-200 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                    <div class="aspect-[4/3] bg-ink-100 relative overflow-hidden">
                        @if ($classImage)
                            {{-- The photograph the operator uploaded against this
                                 class, in the admin panel. --}}
                            <img src="{{ Storage::disk('public')->url($classImage) }}"
                                 alt="{{ $class->name }}"
                                 loading="lazy" width="480" height="360"
                                 class="absolute inset-0 size-full object-cover [transition:transform_300ms_var(--ease-out-strong)] group-hover:scale-105">
                        @else
                            {{-- No photograph uploaded yet.

                                 This was grey make-and-model text on a grey
                                 panel, which failed two ways at once. It
                                 repeated what the card body prints 40px below
                                 it, and repeated text in an image slot is
                                 precisely what a broken <img> looks like — so
                                 a deliberate design choice read as a fault.
                                 The colours were also gray-on-gray at roughly
                                 2.3:1, under the 3:1 large-text floor.

                                 An illustration instead, matching
                                 x-vehicle-card and vehicle.blade.php so one
                                 condition has exactly one treatment across the
                                 site. Deliberately a drawing and not a stock
                                 photograph: nobody should mistake it for the
                                 vehicle they are hiring. --}}
                            <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-brand-50 to-brand-100">
                                <svg aria-hidden="true" class="w-2/5 text-brand-600 [transition:transform_300ms_var(--ease-out-strong)] group-hover:scale-105" viewBox="0 0 64 32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 24h52M10 24V15l5-8h22l7 8h6a4 4 0 0 1 4 4v5M14 15h32"/>
                                    <circle cx="18" cy="24" r="4"/><circle cx="46" cy="24" r="4"/>
                                </svg>
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col justify-between p-6">
                        <div>
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="font-display text-lg font-bold text-ink-900">{{ $class->name }}</h3>
                                {{-- The size of the range, not a scarcity
                                     tactic. Counts only bookable cars, so a
                                     retired one is never offered as an option. --}}
                                <span class="shrink-0 rounded-lg bg-ink-50 px-2 py-1 text-xs font-medium text-ink-600">
                                    {{ $entry['vehicleCount'] }} {{ Str::plural('vehicle', $entry['vehicleCount']) }}
                                </span>
                            </div>

                            @if ($class->description)
                                <p class="mt-2 text-sm leading-relaxed text-ink-600">{{ $class->description }}</p>
                            @endif
                        </div>

                        <div class="mt-6">
                            @if ($entry['fromDailyRate'] !== null)
                                {{-- A DAILY RATE, and labelled as one. Spec §1.2
                                     governs the all-in price, which only exists
                                     once there are dates — so this must never
                                     read as a total. "from" because a vehicle
                                     override inside the class can be dearer. --}}
                                <p class="tabular text-sm text-ink-600">
                                    from
                                    <span class="font-display text-xl font-semibold text-ink-900">
                                        ZMW {{ number_format((float) $entry['fromDailyRate'], 2) }}
                                    </span>
                                    per day
                                </p>
                            @endif

                            {{-- A visible affordance, not just the hover lift.
                                 Hover does not exist on a touch screen, so a
                                 card whose only clue is a lift reads as static
                                 to every phone visitor — and phones are most of
                                 them. --}}
                            <p class="mt-3 flex items-center gap-1.5 text-sm font-semibold text-brand-600">
                                See the {{ Str::lower($class->name) }} range
                                <svg aria-hidden="true" class="size-4 [transition:transform_200ms_var(--ease-out-strong)] group-hover:translate-x-1" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>
                                </svg>
                            </p>
                        </div>
                    </div>
                </a>
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
