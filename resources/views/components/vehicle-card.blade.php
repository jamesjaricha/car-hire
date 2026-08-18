@props(['vehicle', 'quote', 'available' => 1, 'branchId' => null, 'pickupInput' => null, 'dropoffInput' => null])

@php
    $class = $vehicle->vehicleClass;

    // Always this car's own photograph, or the silhouette. Class pictures no
    // longer appear here, so the alt text can name the car without qualifying
    // it — there is nothing left for it to be wrong about.
    $image = $vehicle->primaryImagePath();
    $imageAlt = $vehicle->displayName();

    // The dates travel with the link so the detail page prices the same hire.
    // Carrying them in the URL rather than the session means the page can be
    // shared, bookmarked and reloaded — and that the back button works.
    $detailUrl = route('vehicles.show', [
        'vehicle' => $vehicle->id,
        'branch' => $branchId ?? $vehicle->branch_id,
        'pickup' => $pickupInput,
        'dropoff' => $dropoffInput,
    ]);
@endphp

{{--
| One vehicle class, priced for the customer's dates.
|--------------------------------------------------------------------------
|
| DESIGNED TO WORK WITHOUT A PHOTOGRAPH.
|
| Most small operators have no photography, and a card built around an image
| that does not exist looks broken rather than sparse. So the card is built from
| type and specification chips, and a photograph is an improvement layered on
| top when one has been uploaded.
|
| It is this car's own photograph or the silhouette — nothing in between.
| Class photographs are a home-page thing, where a card stands for a range
| rather than a registration. `x-vehicle-image` is the single owner of that
| markup.
--}}
<article class="rise liftable group flex flex-col overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm hover:shadow-lg hover:shadow-ink-900/5">

    <div class="relative aspect-[16/10] overflow-hidden bg-ink-100">
        <x-vehicle-image :path="$image"
                         :alt="$imageAlt"
                         width="640" height="400" />

        @if ($available > 1)
            <span class="absolute left-3 top-3 rounded-full bg-veld-600 px-2.5 py-1 text-xs font-semibold text-white">
                {{ $available }} available
            </span>
        @else
            {{-- Scarcity stated only when it is true. --}}
            <span class="absolute left-3 top-3 rounded-full bg-ember-600 px-2.5 py-1 text-xs font-semibold text-white">
                Last one
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <h2 class="font-display text-lg font-semibold tracking-tight text-ink-900">
            {{ $class?->name ?? 'Vehicle' }}
        </h2>
        {{-- "or similar" only when the picture is a stand-in.

             It is the hire trade's standard hedge and it was harmless while
             every card showed a class photograph. Above a photograph of THIS
             car it contradicts the image directly — the customer is looking at
             a specific Corolla and being told they might get a different one,
             which undoes the trust the photograph was added to build. The
             booking locks this vehicle row, so when we can show it, we say it. --}}
        <p class="mt-0.5 text-sm text-ink-500">
            {{ $vehicle->make }} {{ $vehicle->model }}@unless ($vehicle->hasOwnImages()) or similar @endunless
        </p>

        {{-- Specification chips carry the card where a photograph would. Each
             is a fact a customer actually decides on. --}}
        <ul class="mt-3 flex flex-wrap gap-1.5" aria-label="Vehicle details">
            @foreach ([
                $vehicle->seats.' seats',
                ucfirst((string) $vehicle->transmission),
                ucfirst((string) $vehicle->fuel_type),
            ] as $spec)
                <li class="rounded-lg bg-ink-100 px-2 py-1 text-xs font-medium text-ink-700">{{ $spec }}</li>
            @endforeach
        </ul>

        <div class="mt-4 border-t border-ink-200 pt-4">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-500">
                        Total for {{ $quote->chargeableDays }} {{ Str::plural('day', $quote->chargeableDays) }}
                    </p>
                    <p class="tabular font-display text-2xl font-semibold text-ink-900">
                        {{ $quote->currency }} {{ number_format((float) $quote->grandTotal, 2) }}
                    </p>
                    <p class="tabular mt-0.5 text-xs text-ink-500">
                        {{ $quote->currency }} {{ number_format((float) $quote->dailyRate, 2) }} per day, insurance included
                    </p>
                </div>
            </div>

            {{-- Spec §6: shown here, in search results, because it must never
                 first appear at the counter. Visually separated so it is not
                 mistaken for part of the price above. --}}
            <p class="tabular mt-3 flex items-center gap-1.5 rounded-lg bg-veld-50 px-2.5 py-2 text-xs text-veld-900">
                <svg aria-hidden="true" class="size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/>
                </svg>
                Refundable deposit {{ $quote->currency }} {{ number_format((float) $quote->securityDepositAmount, 2) }},
                paid at the branch
            </p>
        </div>

        {{-- mt-auto so the button sits on the card's floor regardless of how
             many specification chips wrapped above it. Ragged buttons across a
             grid read as carelessness even when nobody can say why. --}}
        <a href="{{ $detailUrl }}"
           class="pressable mt-auto block cursor-pointer rounded-xl bg-brand-600 px-4 pt-3.5 pb-3.5 text-center font-display text-sm font-semibold text-white [transition:transform_160ms_var(--ease-out-strong),background-color_160ms_ease] hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
            View {{ $class?->name ?? 'vehicle' }}
            <span class="sr-only">and continue to booking</span>
        </a>
    </div>
</article>
