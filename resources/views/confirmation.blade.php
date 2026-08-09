@extends('layouts.site')

@section('title', 'Booking '.$booking->reference)

@php
    $zone = config('carhire.display_timezone');
    $money = fn (string|null $amount): string => $booking->currency.' '.number_format((float) ($amount ?? '0'), 2);
    $held = $booking->payment_deadline_at !== null;

    // What is left AFTER the payment being asked for on this page.
    //
    // NOT $booking->balance_due. That is the whole grand total at this moment,
    // because nothing has been paid yet — the customer has been given
    // instructions, not credited. Showing it beside "pay now" reads as two
    // separate debts and makes a K2,220 hire look like K3,330.
    $remainingAfterThisPayment = \App\Support\Money::subtract(
        $booking->grand_total,
        $payment->expected_amount ?? '0',
    );
@endphp

@section('content')

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 sm:py-12">

        {{-- Deliberately not "Booking confirmed". Spec §7.3: proof of payment
             never confirms a booking on its own, and a customer who reads
             "confirmed" here will not send the money. --}}
        <div class="flex items-start gap-3">
            <span aria-hidden="true" class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-veld-100">
                <svg class="size-5 text-veld-700" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0l-3.5-3.5a1 1 0 111.4-1.4l2.8 2.79 6.8-6.79a1 1 0 011.4 0z" clip-rule="evenodd"/>
                </svg>
            </span>
            <div>
                <h1 class="font-display text-2xl font-semibold tracking-tight text-ink-900 sm:text-3xl">
                    We have reserved your vehicle
                </h1>
                <p class="mt-1.5 text-ink-600">
                    It is not confirmed until we have received your payment. Here is what to do next.
                </p>
            </div>
        </div>

        {{-- ── The reference ──────────────────────────────────────────────────
             The single most important thing on the page. A customer quotes this
             on a bank transfer or a mobile money reference field, and staff
             match it against a statement — an unmatched payment is money nobody
             can attribute. Large, copyable, tabular. --}}
        <div class="mt-8 overflow-hidden rounded-2xl border border-ink-800 bg-ink-900 text-white">
            <div class="p-6">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-400">
                    Your payment reference — quote this exactly
                </p>

                {{-- Copyable, because a mistyped reference is the single most
                     common cause of a payment nobody can attribute — and an
                     unattributed payment is a customer who has paid and is not
                     confirmed. The button is a convenience over the text, not a
                     replacement for it: the reference stays selectable for
                     anyone whose clipboard is unavailable. --}}
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <p class="tabular font-display text-3xl font-semibold tracking-tight sm:text-4xl"
                       id="payment-reference">{{ $payment->payment_reference }}</p>

                    <button type="button"
                            data-copy="{{ $payment->payment_reference }}"
                            class="pressable inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white [transition:transform_160ms_var(--ease-out-strong),background-color_160ms_ease] hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M7 3.5A1.5 1.5 0 018.5 2h3.879a1.5 1.5 0 011.06.44l3.122 3.12A1.5 1.5 0 0117 6.62V15.5a1.5 1.5 0 01-1.5 1.5h-7A1.5 1.5 0 017 15.5v-12z"/>
                            <path d="M4.5 6A1.5 1.5 0 003 7.5v9A1.5 1.5 0 004.5 18h7a1.5 1.5 0 001.5-1.5v-.5h-4A2.5 2.5 0 016.5 13.5V6h-2z"/>
                        </svg>
                        <span data-copy-label>Copy</span>
                    </button>
                </div>
            </div>

            <div class="grid gap-px border-t border-white/10 bg-white/10 sm:grid-cols-2">
                <div class="bg-ink-900 p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Amount to pay now</p>
                    <p class="tabular mt-1 font-display text-2xl font-semibold">
                        {{ $money($payment->expected_amount) }}
                    </p>
                    @if (\App\Support\Money::isPositive($remainingAfterThisPayment))
                        <p class="tabular mt-1 text-sm text-ink-300">
                            The remaining {{ $money($remainingAfterThisPayment) }} is due at the branch
                            before you collect.
                        </p>
                    @else
                        <p class="mt-1 text-sm text-ink-300">
                            That settles the hire in full. Only the refundable deposit is left to pay
                            at the branch.
                        </p>
                    @endif
                </div>

                <div class="bg-ink-900 p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-400">Pay by</p>
                    @if ($held)
                        <p class="tabular mt-1 font-display text-2xl font-semibold">
                            {{ $booking->payment_deadline_at->setTimezone($zone)->format('j M, H:i') }}
                        </p>
                        <p class="mt-1 text-sm text-ink-300">
                            Your vehicle is held until then.
                        </p>
                    @else
                        {{-- Spec §8.2: inside the short-notice window no hold is
                             placed and availability is first-come at the
                             counter. Saying otherwise would be a promise the
                             operator cannot keep. --}}
                        <p class="mt-1 font-display text-lg font-semibold text-ember-300">
                            At the branch
                        </p>
                        <p class="mt-1 text-sm text-ink-300">
                            Your pickup is soon, so no vehicle is held — availability is
                            first-come when you arrive.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── Payment instructions ───────────────────────────────────────────
             Rendered by the method's own adapter, with the operator's account
             details merged in. Empty when the operator has not configured that
             method — which cannot happen for a method a customer was able to
             choose, because unconfigured methods are withheld at checkout. --}}
        @if (trim($instructions) !== '')
            <section class="mt-6 rounded-2xl border border-brand-200 bg-brand-50 p-6">
                <h2 class="font-display text-lg font-semibold text-brand-900">
                    How to pay by {{ $method?->label ?? 'your chosen method' }}
                </h2>
                <p class="mt-3 whitespace-pre-line leading-relaxed text-brand-900/90">{{ $instructions }}</p>

                @if ($method?->account_details)
                    <dl class="mt-4 grid gap-3 border-t border-brand-200 pt-4 sm:grid-cols-2">
                        @foreach ($method->account_details as $label => $value)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-brand-700">
                                    {{ Str::headline((string) $label) }}
                                </dt>
                                <dd class="tabular mt-0.5 font-semibold text-brand-950">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>
        @endif

        {{-- ── What happens next ──────────────────────────────────────────────
             The whole reason this page is designed the way it is. There is no
             card gateway and no instant confirmation, so a customer who has
             just been asked to transfer real money needs to know precisely what
             follows and when. --}}
        <section class="mt-6 rounded-2xl border border-ink-200 p-6">
            <h2 class="font-display text-lg font-semibold text-ink-900">What happens next</h2>
            <ol class="mt-4 space-y-4">
                @foreach ([
                    ['Send the payment', 'Use the reference above so we can match it to your booking.'],
                    ['We verify it', 'A member of staff checks it against our statement. This is not automatic.'],
                    ['You are confirmed', 'We email and text you once your booking is confirmed.'],
                    ['Collect your vehicle', 'Bring your driving licence and the refundable security deposit.'],
                ] as $index => [$heading, $body])
                    <li class="flex gap-3">
                        <span aria-hidden="true"
                              class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-ink-100 font-display text-sm font-semibold text-ink-700">
                            {{ $index + 1 }}
                        </span>
                        <div>
                            <p class="font-semibold text-ink-900">{{ $heading }}</p>
                            <p class="mt-0.5 text-sm leading-relaxed text-ink-600">{{ $body }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- ── The booking itself ─────────────────────────────────────────── --}}
        <section class="mt-6 rounded-2xl border border-ink-200 p-6">
            <h2 class="font-display text-lg font-semibold text-ink-900">Your booking</h2>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'Booking reference' => $booking->reference,
                    'Vehicle' => $booking->vehicle_class_name.' — '.$booking->vehicle_make.' '.$booking->vehicle_model,
                    'Collecting from' => $booking->pickupBranch?->name,
                    'Registration' => $booking->vehicle_registration,
                    'Pickup' => $booking->pickup_at->setTimezone($zone)->format('D j M Y, H:i'),
                    'Return' => $booking->dropoff_at->setTimezone($zone)->format('D j M Y, H:i'),
                ] as $label => $value)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ink-500">{{ $label }}</dt>
                        <dd class="tabular mt-0.5 font-semibold text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <dl class="mt-6 space-y-2 border-t border-ink-200 pt-4 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-600">Hire, {{ $booking->chargeable_days }} {{ Str::plural('day', $booking->chargeable_days) }}</dt>
                    <dd class="tabular font-medium text-ink-900">{{ $money($booking->hire_total) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-600">Damage waiver</dt>
                    <dd class="tabular font-medium text-ink-900">{{ $money($booking->insurance_total) }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-ink-200 pt-2">
                    <dt class="font-display font-semibold text-ink-900">Hire total</dt>
                    <dd class="tabular font-display text-lg font-semibold text-ink-900">{{ $money($booking->grand_total) }}</dd>
                </div>
            </dl>

            {{-- Spec §6, restated at the last possible moment before collection.
                 It must never be a surprise at the counter. --}}
            <div class="mt-4 rounded-xl bg-veld-50 p-4 text-sm">
                <p class="font-semibold text-veld-900">Bring to the branch</p>
                <p class="tabular mt-1 text-veld-900/85">
                    A refundable security deposit of {{ $money($booking->security_deposit_amount) }} in cash,
                    returned to you when you bring the vehicle back. Your driving licence, and the card or
                    phone you paid with.
                </p>
                <p class="tabular mt-2 text-veld-900/85">
                    If the vehicle is damaged you remain liable for up to
                    {{ $money($booking->insurance_excess_amount) }}.
                </p>
            </div>
        </section>

        <p class="mt-6 text-center text-sm text-ink-500">
            Keep this page. You can return to it at any time using your reference.
        </p>
    </div>

@endsection
