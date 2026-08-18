@props([
    'path' => null,
    'alt' => '',
    'width' => 640,
    'height' => 400,
    'loading' => 'lazy',
    'imgClass' => 'size-full object-cover',
    'panelClass' => 'size-full',
    'glyphClass' => 'w-2/5',
])

{{--
| A photograph of a vehicle, or the illustration that stands in for one.
|--------------------------------------------------------------------------
|
| WHY THIS COMPONENT EXISTS
|
| This markup was copy-pasted into home.blade.php, x-vehicle-card and
| vehicle.blade.php. It drifted: the home page drew grey make-and-model text on
| a grey panel while the other two drew the silhouette, and the home page was
| the one being demonstrated to the operator. Repeated text in an image slot is
| exactly what a broken <img> looks like, so a deliberate design choice read as
| a fault. The three were realigned by hand on 2026-08-13 and OPEN-ITEMS
| recorded that fixing the symptom had left the cause.
|
| Per-vehicle photographs touch all four call sites, which is the moment the
| note said to do this once rather than twice.
|
| THE ILLUSTRATION IS NOT A STAND-IN PHOTOGRAPH
|
| It is deliberately a drawing. A stock photograph of a similar car is the thing
| this whole slice exists to stop: nobody should be able to mistake the picture
| for the vehicle they are hiring.
|
| The glyph is at full `brand-600` rather than tinted. At 70% opacity it
| measured about 2.5:1 against the panel and read as a missing asset. It is
| `aria-hidden` and decorative, so WCAG does not strictly require 3:1 — but
| being visible is the entire point of it.
|
| The caller owns the sized box; this owns what goes inside it. Hence the class
| props: the three call sites need different aspect handling, positioning and
| glyph sizes, and passing them in beats three components that drift apart
| again.
--}}
@if ($path)
    <img src="{{ Storage::disk('public')->url($path) }}"
         alt="{{ $alt }}"
         loading="{{ $loading }}"
         {{-- Dimensions reserve the space so the layout does not jump when the
              image arrives. --}}
         width="{{ $width }}" height="{{ $height }}"
         class="{{ $imgClass }}">
@else
    <div class="flex items-center justify-center bg-gradient-to-br from-brand-50 to-brand-100 {{ $panelClass }}">
        <svg aria-hidden="true" class="text-brand-600 {{ $glyphClass }}" viewBox="0 0 64 32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 24h52M10 24V15l5-8h22l7 8h6a4 4 0 0 1 4 4v5M14 15h32"/>
            <circle cx="18" cy="24" r="4"/><circle cx="46" cy="24" r="4"/>
        </svg>
    </div>
@endif
