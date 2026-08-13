@extends('layouts.site')

@section('title', $class->name.' hire')

@php
    $zone = config('carhire.display_timezone');
    $images = $class->imagePaths();
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
                    @if ($images !== [])
                        <img src="{{ Storage::disk('public')->url($images[0]) }}"
                             alt="{{ $class->name }}"
                             width="960" height="600"
                             class="aspect-[16/10] w-full object-cover">
                    @else
                        <div class="flex aspect-[16/10] w-full items-center justify-center bg-gradient-to-br from-ink-100 to-ink-200">
                            <svg aria-hidden="true" class="w-1/3 text-ink-400" viewBox="0 0 64 32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 24h52M10 24V15l5-8h22l7 8h6a4 4 0 0 1 4 4v5M14 15h32"/>
                                <circle cx="18" cy="24" r="4"/><circle cx="46" cy="24" r="4"/>
                            </svg>
                        </div>
                    @endif
                </div>

                {{-- The rest of the gallery, when there is one. --}}
                @if (count($images) > 1)
                    <ul class="mt-3 grid grid-cols-4 gap-3">
                        @foreach (array_slice($images, 1, 4) as $path)
                            <li class="overflow-hidden rounded-xl border border-ink-200">
                                <img src="{{ Storage::disk('public')->url($path) }}"
                                     alt=""
                                     loading="lazy"
                                     width="240" height="160"
                                     class="aspect-[3/2] w-full object-cover">
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h1 class="mt-6 font-display text-3xl font-semibold tracking-tight text-ink-900">
                    {{ $class->name }}
                </h1>
                <p class="mt-1 text-ink-600">
                    {{ $vehicle->make }} {{ $vehicle->model }}{{ $vehicle->year ? ', '.$vehicle->year : '' }} or similar
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
