@props(['vehicle', 'quote', 'available' => 1, 'branchId' => null, 'pickupInput' => null, 'dropoffInput' => null])

@php
    $class = $vehicle->vehicleClass;
    $image = $class?->primaryImagePath();

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
| top when one has been uploaded. The silhouette is deliberately an illustration
| and not a stand-in for a real car — nobody should mistake it for the vehicle
| they are hiring.
|
| The fallback chain is class photograph, then silhouette. When per-vehicle
| photographs arrive, they slot in ahead of the class without this markup
| changing shape.
--}}
<article class="rise liftable group flex flex-col overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm hover:shadow-lg hover:shadow-ink-900/5">

    <div class="relative aspect-[16/10] overflow-hidden bg-ink-100">
        @if ($image)
            <img src="{{ Storage::disk('public')->url($image) }}"
                 alt="{{ $class->name }}"
                 loading="lazy"
                 {{-- Dimensions reserve the space so the card does not jump
                      when the image arrives. --}}
                 width="640" height="400"
                 class="size-full object-cover">
        @else
            {{-- Brand tint rather than ink grey, and the glyph at full strength.
                 `ink-400` on an `ink-100`→`ink-200` panel measured about 2.3:1,
                 which is invisible enough to read as a missing asset. Same
                 treatment as home.blade.php and vehicle.blade.php. --}}
            <div class="flex size-full items-center justify-center bg-gradient-to-br from-brand-50 to-brand-100">
                <svg aria-hidden="true" class="w-2/5 text-brand-600" viewBox="0 0 64 32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 24h52M10 24V15l5-8h22l7 8h6a4 4 0 0 1 4 4v5M14 15h32"/>
                    <circle cx="18" cy="24" r="4"/><circle cx="46" cy="24" r="4"/>
                </svg>
            </div>
        @endif

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
        <p class="mt-0.5 text-sm text-ink-500">
            {{ $vehicle->make }} {{ $vehicle->model }} or similar
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
