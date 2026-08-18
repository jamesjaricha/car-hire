@extends('layouts.site')

@section('title', $class->name.' hire')

@php
    $zone = config('carhire.display_timezone');

    // THIS car's photographs, or none. Class photographs are a home-page thing
    // now — see Vehicle::imagePaths(). So anything rendered here is genuinely
    // the vehicle being booked, and the page no longer needs a caption
    // explaining that its pictures are of something else.
    $images = $vehicle->imagePaths();
    $imageAlt = $vehicle->displayName();

    $money = fn (string $amount): string => $quote->currency.' '.number_format((float) $amount, 2);
@endphp

@section('content')

    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">

        <a href="{{ route('search', ['branch' => $branch->id, 'pickup' => $pickupInput, 'dropoff' => $dropoffInput]) }}"
           class="inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-ink-600 transition-colors duration-150 hover:text-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
            <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd"/>
            </svg>
            Back to results
        </a>

        {{-- Reached only if a POST straight to basket.store carries a bad
             vehicle, branch or date pair — not reachable through this page's
             own form, whose fields are hidden and copied from a URL that was
             already validated to render it. Kept anyway: search-form.blade.php
             and checkout.blade.php both had the same class of bug, where a
             failure rendered nowhere and the customer was sent back to a page
             giving no reason why. --}}
        @if ($errors->any())
            <div class="mt-4 flex items-start gap-2 rounded-xl border border-danger-100 bg-danger-50 px-4 py-3 text-sm text-danger-700" role="alert">
                <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-danger-600" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                </svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <div class="mt-4 grid gap-8 lg:grid-cols-[1.4fr_1fr]">

            {{-- ── The vehicle ─────────────────────────────────────────────── --}}
            <div>
                <div class="overflow-hidden rounded-2xl border border-ink-200 bg-ink-100">
                    {{-- `eager`, not lazy: this is the hero, above the fold. --}}
                    <x-vehicle-image :path="$images[0] ?? null"
                                     :alt="$imageAlt"
                                     loading="eager"
                                     width="960" height="600"
                                     imgClass="aspect-[16/10] w-full object-cover"
                                     panelClass="aspect-[16/10] w-full"
                                     glyphClass="w-1/3"
                                     data-gallery-hero />
                </div>

                {{-- There is no longer a "these photographs are of a different
                     car" caption here, and that is the point rather than an
                     omission.

                     It existed because the page could fall back to the class
                     gallery. Removing that fallback removed the thing the
                     caption apologised for: every photograph on this page is
                     now of this registration, and a car with none shows the
                     illustrated silhouette, which nobody can mistake for a
                     photograph. A page that does not show the wrong thing beats
                     a page that explains why it is showing the wrong thing. --}}

                {{-- The rest of the gallery.
                     ─────────────────────────────────────────────────────────
                     THREE FAULTS FIXED HERE, all reported by the operator as
                     "no option to scroll the vehicle images".

                     1. The thumbnails were plain <img> inside <li>. Nothing was
                        clickable, so an uploaded photograph could be seen at
                        80px and never any larger. They are <button>s now and
                        they swap the hero.
                     2. `array_slice($images, 1, 4)` capped the strip at four.
                        The upload allows SIX, so the sixth was rendered
                        nowhere at all — uploaded, stored, paid for in
                        somebody's time, and invisible.
                     3. A four-column grid cannot scroll. On a phone six
                        thumbnails in four columns wrap into a second row that
                        pushes the price below the fold. A single overflow-x
                        row scrolls on a phone and fits on a desktop.

                     EVERY image is listed, including the first, so the strip
                     is the complete set and the hero is simply whichever is
                     selected. A strip that silently omits the picture you are
                     looking at makes the count wrong and the selection state
                     meaningless.

                     Without JavaScript nothing is lost: every photograph is
                     still on the page and still visible, just not enlargeable.
                     Same progressive-enhancement rule as the copy button. --}}
                @if (count($images) > 1)
                    {{-- `data-gallery-strip`, not `data-gallery-thumbs`. The
                         container and the buttons must not differ by a single
                         trailing character: `data-gallery-thumb` is a strict
                         PREFIX of `data-gallery-thumbs`, so any grep, count or
                         substring match over the markup silently includes the
                         container as though it were a seventh thumbnail. It
                         did exactly that in the test for this feature. --}}
                    <ul class="mt-3 flex snap-x snap-mandatory gap-3 overflow-x-auto pb-1" data-gallery-strip>
                        @foreach ($images as $index => $path)
                            <li class="shrink-0 snap-start">
                                {{-- A button, not a div: this is a control, so
                                     it must be reachable by keyboard and
                                     announce itself. `aria-current` carries the
                                     selected state for both the styling and the
                                     screen reader, rather than a class that
                                     only one of them can see. --}}
                                <button type="button"
                                        data-gallery-thumb
                                        data-full="{{ Storage::disk('public')->url($path) }}"
                                        @if ($index === 0) aria-current="true" @endif
                                        class="block cursor-pointer overflow-hidden rounded-xl border-2 border-ink-200 transition-colors duration-150 hover:border-brand-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700 aria-[current=true]:border-brand-600">
                                    <img src="{{ Storage::disk('public')->url($path) }}"
                                         alt="Photograph {{ $index + 1 }} of {{ count($images) }}"
                                         loading="lazy"
                                         width="240" height="160"
                                         class="aspect-[3/2] w-20 object-cover sm:w-24">
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h1 class="mt-6 font-display text-3xl font-semibold tracking-tight text-ink-900">
                    {{ $class->name }}
                </h1>
                {{-- Names the car, and only the car.

                     "Or similar" is the hire trade's standard hedge and it is
                     removed here rather than made conditional. This page
                     already promises, six lines down under "What is included",
                     that "a specific vehicle is held for you once you reserve"
                     — which is true, because VehicleHoldService::place() locks
                     this row. A hedge sitting above that promise contradicted
                     it whether or not a photograph was involved, so the
                     photograph work merely made an existing tension visible.

                     The colour is now always shown rather than only alongside
                     an own photograph. It is a plain fact about the car, and it
                     matters MOST when there is no photograph — with a stand-in
                     picture it is the only thing telling somebody what they are
                     collecting.

                     ⚠ Spec §8.3 lets staff reassign a booking to another
                     vehicle of the same class, so "or similar" was not pure
                     boilerplate. That is a commercial copy decision for the
                     operator, flagged rather than settled here. --}}
                <p class="mt-1 text-ink-600">
                    {{ $vehicle->make }} {{ $vehicle->model }}{{ $vehicle->year ? ', '.$vehicle->year : '' }}{{ $vehicle->colour ? ', '.strtolower((string) $vehicle->colour) : '' }}
                </p>

                @if ($class->description)
                    <p class="mt-4 max-w-prose leading-relaxed text-ink-700">{{ $class->description }}</p>
                @endif

                <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        'Seats' => $vehicle->seats,
                        'Transmission' => ucfirst((string) $vehicle->transmission),
                        'Fuel' => ucfirst((string) $vehicle->fuel_type),
                        'Collecting from' => $branch->name,
                    ] as $label => $value)
                        <div class="rounded-xl border border-ink-200 p-3">
                            <dt class="text-xs font-medium uppercase tracking-wide text-ink-500">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                {{-- Spec §10: the excess must be stated before payment, not
                     discovered after a claim. --}}
                <div class="mt-6 rounded-2xl border border-ink-200 p-5">
                    <h2 class="font-display text-base font-semibold text-ink-900">What is included</h2>
                    <ul class="mt-3 space-y-2 text-sm text-ink-700">
                        @foreach ([
                            'Damage waiver, already in the price shown',
                            'Unlimited mileage',
                            'A specific vehicle held for you once you reserve',
                        ] as $included)
                            <li class="flex items-start gap-2">
                                <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-veld-600" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0l-3.5-3.5a1 1 0 111.4-1.4l2.8 2.79 6.8-6.79a1 1 0 011.4 0z" clip-rule="evenodd"/>
                                </svg>
                                {{ $included }}
                            </li>
                        @endforeach
                        <li class="flex items-start gap-2 text-ink-600">
                            <svg aria-hidden="true" class="mt-0.5 size-4 shrink-0 text-ink-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                            </svg>
                            If the vehicle is damaged you remain liable for up to
                            <strong class="tabular font-semibold text-ink-900">{{ $money($quote->insuranceExcessAmount) }}</strong>.
                        </li>
                    </ul>
                </div>
            </div>

            {{-- ── The price ───────────────────────────────────────────────────
                 A money surface: heavier, quieter, closer to a receipt. Every
                 figure is itemised, because §1.2 forbids introducing a
                 mandatory charge later than the search result and the only way
                 to show that is to show the sum. --}}
            <div class="lg:sticky lg:top-6 lg:self-start">
                <div class="overflow-hidden rounded-2xl border border-ink-800 bg-ink-900 text-white">
                    <div class="border-b border-white/10 p-5">
                        <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Your hire</p>
                        <p class="tabular mt-1 text-sm text-ink-200">
                            {{ $range->start->setTimezone($zone)->format('D j M, H:i') }}
                            &rarr;
                            {{ $range->end->setTimezone($zone)->format('D j M, H:i') }}
                        </p>
                    </div>

                    <dl class="space-y-2.5 p-5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-300">
                                Hire, {{ $quote->chargeableDays }} {{ Str::plural('day', $quote->chargeableDays) }}
                                &times; {{ $money($quote->dailyRate) }}
                            </dt>
                            <dd class="tabular font-medium">{{ $money($quote->hireTotal) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-300">Damage waiver</dt>
                            <dd class="tabular font-medium">{{ $money($quote->insuranceTotal) }}</dd>
                        </div>

                        <div class="flex justify-between gap-4 border-t border-white/10 pt-3">
                            <dt class="font-display text-base font-semibold">Total</dt>
                            <dd class="tabular font-display text-xl font-semibold">{{ $money($quote->grandTotal) }}</dd>
                        </div>
                    </dl>

                    {{-- The two deposits, kept apart and named in full. The
                         specification calls conflating them the single most
                         likely misreading in the whole document. --}}
                    <div class="space-y-3 border-t border-white/10 bg-white/5 p-5 text-sm">
                        <div>
                            <p class="font-semibold">To reserve now</p>
                            <p class="tabular mt-0.5 text-ink-200">
                                {{ $money($quote->bookingDepositAmount) }}
                                ({{ $quote->depositPercentage }}% of the hire) — or pay the full
                                {{ $money($quote->grandTotal) }} if you prefer.
                            </p>
                        </div>
                        <div class="rounded-xl bg-veld-600/15 p-3">
                            <p class="font-semibold text-veld-100">Refundable security deposit</p>
                            <p class="tabular mt-0.5 text-veld-100/85">
                                {{ $money($quote->securityDepositAmount) }} in cash at the branch, returned
                                when you bring the vehicle back. This is <em>not</em> part of the hire price.
                            </p>
                        </div>
                    </div>

                    <div class="p-5 pt-0">
                        @if ($stillAvailable)
                            <form method="POST" action="{{ route('basket.store') }}">
                                @csrf
                                <input type="hidden" name="vehicle" value="{{ $vehicle->id }}">
                                <input type="hidden" name="branch" value="{{ $branch->id }}">
                                <input type="hidden" name="pickup" value="{{ $pickupInput }}">
                                <input type="hidden" name="dropoff" value="{{ $dropoffInput }}">

                                <button type="submit"
                                        class="pressable w-full cursor-pointer rounded-xl bg-brand-500 px-4 py-3.5 font-display text-base font-semibold text-white [transition:transform_160ms_var(--ease-out-strong),background-color_160ms_ease] hover:bg-brand-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                                    Reserve this vehicle
                                </button>
                            </form>
                            <p class="mt-3 text-center text-xs leading-relaxed text-ink-400">
                                Nothing is charged now. You will be given payment instructions and a
                                deadline, and the vehicle is held for you until then.
                            </p>
                        @else
                            {{-- Availability is advisory, so this can change
                                 between the results page and here. Saying so
                                 beats a Reserve button that fails. --}}
                            <p class="rounded-xl bg-ember-600/15 p-3 text-center text-sm text-ember-100">
                                This vehicle has just been taken for those dates.
                                <a href="{{ route('search', ['branch' => $branch->id, 'pickup' => $pickupInput, 'dropoff' => $dropoffInput]) }}"
                                   class="rounded font-semibold underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">See what else is free</a>.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
