@props(['message'])

{{--
| A flashed message the customer needs to read.
|--------------------------------------------------------------------------
|
| NOT a toast, deliberately. Every message routed through here explains why
| something the customer expected did not happen — a vehicle taken while they
| were typing, a basket that lapsed — and two of the three say "nothing has
| been charged". Auto-dismissing a sentence about money after four seconds is
| how somebody ends up believing they have paid twice.
|
| So it stays on the page until they navigate away, and it is placed at the top
| of the content rather than floating over it, so it cannot cover what it is
| explaining.
|
| `role="status"` with `aria-live="polite"` announces it to a screen reader
| after the page settles, without stealing focus from wherever the customer is.
--}}
<div role="status"
     aria-live="polite"
     class="rise mx-auto mt-6 flex max-w-6xl items-start gap-3 rounded-2xl border border-ember-500/30 bg-ember-100 px-4 py-3.5 text-ember-700 sm:px-6">

    <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
    </svg>

    <p class="text-sm leading-relaxed">{{ $message }}</p>
</div>
