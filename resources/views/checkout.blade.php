@extends('layouts.site')

@section('title', 'Checkout')

@php
    $zone = config('carhire.display_timezone');
    $money = fn (string $amount): string => $quote->currency.' '.number_format((float) $amount, 2);
@endphp

@section('content')

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">

        <h1 class="font-display text-2xl font-semibold tracking-tight text-ink-900 sm:text-3xl">
            Confirm your booking
        </h1>
        <p class="mt-2 text-ink-600">
            Nothing is charged now. You will be given payment instructions and a deadline.
        </p>

        @if ($expiresAt)
            {{-- Spec §1.1: thirty minutes of inactivity. Saying so is fairer
                 than letting it lapse silently while somebody finds their
                 licence. --}}
            <p class="tabular mt-3 inline-flex items-center gap-1.5 rounded-lg bg-ember-100 px-3 py-1.5 text-sm text-ember-700">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .28.16.54.4.67l3 1.5a.75.75 0 10.7-1.34L10.75 9.5V5z" clip-rule="evenodd"/>
                </svg>
                This price is held until {{ $expiresAt->setTimezone($zone)->format('H:i') }}.
            </p>
        @endif

        <form method="POST" action="{{ route('checkout.store') }}" class="mt-8 grid gap-8 lg:grid-cols-[1.3fr_1fr]">
            @csrf

            <div class="space-y-6">

                {{-- ── Contact details ───────────────────────────────────────
                     Spec §1.3: three fields, asked once, at the end. No account
                     is created and none is required.

                     §1.4 is invisible here on purpose — this form renders
                     identically whether or not the email already exists, so
                     nobody can enumerate customers by starting checkouts. --}}
                <section class="rounded-2xl border border-ink-200 p-5">
                    <h2 class="font-display text-lg font-semibold text-ink-900">Your details</h2>
                    <p class="mt-1 text-sm text-ink-600">
                        We need these to confirm your booking and send your payment instructions.
                    </p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="full_name" class="block text-sm font-medium text-ink-800">
                                Full name <span class="text-danger-600" aria-hidden="true">*</span>
                            </label>
                            {{-- Focus jumps to the first field with a problem
                                 after a failed submit. Without it the page
                                 reloads at the top and the customer has to
                                 hunt for what went wrong — on a phone, where
                                 the errors are below the fold, that is most of
                                 the form. --}}
                            <input id="full_name" name="full_name" type="text" required
                                   autocomplete="name"
                                   @if ($errors->has('full_name')) autofocus @endif
                                   aria-describedby="{{ $errors->has('full_name') ? 'full_name_error' : '' }}"
                                   value="{{ old('full_name') }}"
                                   @class([
                                       'mt-1.5 w-full rounded-xl py-3 text-base shadow-sm focus:ring-2 focus:ring-brand-600/30',
                                       'border-ink-300 focus:border-brand-600' => ! $errors->has('full_name'),
                                       'border-danger-600 focus:border-danger-600' => $errors->has('full_name'),
                                   ])>
                            @error('full_name')
                                <p id="full_name_error" class="mt-1.5 text-sm text-danger-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-ink-800">
                                Email <span class="text-danger-600" aria-hidden="true">*</span>
                            </label>
                            {{-- type=email brings up the right keyboard on a
                                 phone and lets the browser autofill. --}}
                            <input id="email" name="email" type="email" required
                                   autocomplete="email" inputmode="email"
                                   @if ($errors->has('email') && ! $errors->has('full_name')) autofocus @endif
                                   aria-describedby="{{ $errors->has('email') ? 'email_error' : '' }}"
                                   value="{{ old('email') }}"
                                   @class([
                                       'mt-1.5 w-full rounded-xl py-3 text-base shadow-sm focus:ring-2 focus:ring-brand-600/30',
                                       'border-ink-300 focus:border-brand-600' => ! $errors->has('email'),
                                       'border-danger-600 focus:border-danger-600' => $errors->has('email'),
                                   ])>
                            @error('email')
                                <p id="email_error" class="mt-1.5 text-sm text-danger-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-ink-800">
                                Mobile number <span class="text-danger-600" aria-hidden="true">*</span>
                            </label>
                            <input id="phone" name="phone" type="tel" required
                                   autocomplete="tel" inputmode="tel"
                                   placeholder="097 000 0000"
                                   @if ($errors->has('phone') && ! $errors->hasAny(['full_name', 'email'])) autofocus @endif
                                   aria-describedby="phone_help{{ $errors->has('phone') ? ' phone_error' : '' }}"
                                   value="{{ old('phone') }}"
                                   @class([
                                       'mt-1.5 w-full rounded-xl py-3 text-base shadow-sm focus:ring-2 focus:ring-brand-600/30',
                                       'border-ink-300 focus:border-brand-600' => ! $errors->has('phone'),
                                       'border-danger-600 focus:border-danger-600' => $errors->has('phone'),
                                   ])>
                            <p id="phone_help" class="mt-1.5 text-sm text-ink-500">
                                097…, +26097… and 26097… are all understood. We send your payment
                                reminder here.
                            </p>
                            @error('phone')
                                <p id="phone_error" class="mt-1.5 text-sm text-danger-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </section>

                {{-- ── How much to pay now ───────────────────────────────────
                     Spec §5. Two options, both stated in money rather than in
                     percentages alone. --}}
                <section class="rounded-2xl border border-ink-200 p-5">
                    <h2 class="font-display text-lg font-semibold text-ink-900">How much would you like to pay now?</h2>

                    <div class="mt-4 space-y-3">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ink-300 p-4 transition-colors duration-150 has-checked:border-brand-600 has-checked:bg-brand-50">
                            <input type="radio" name="pay_in_full" value="0" checked
                                   class="mt-1 size-4 border-ink-400 text-brand-600 focus:ring-brand-600">
                            <span>
                                <span class="block font-semibold text-ink-900">
                                    Pay the deposit — {{ $money($quote->bookingDepositAmount) }}
                                </span>
                                <span class="tabular mt-0.5 block text-sm text-ink-600">
                                    {{ $quote->depositPercentage }}% now, and {{ $money($quote->balanceAfterDeposit) }}
                                    at the branch before you collect.
                                </span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ink-300 p-4 transition-colors duration-150 has-checked:border-brand-600 has-checked:bg-brand-50">
                            <input type="radio" name="pay_in_full" value="1"
                                   class="mt-1 size-4 border-ink-400 text-brand-600 focus:ring-brand-600">
                            <span>
                                <span class="block font-semibold text-ink-900">
                                    Pay in full — {{ $money($quote->grandTotal) }}
                                </span>
                                <span class="mt-0.5 block text-sm text-ink-600">
                                    Nothing left to pay at the branch except the refundable deposit.
                                </span>
                            </span>
                        </label>
                    </div>
                </section>

                {{-- ── Payment method ────────────────────────────────────────
                     Only methods that are enabled, configured and valid for
                     this pickup time. §8.2 removes remote methods when pickup
                     is imminent; an unconfigured method is withheld because
                     its instructions would have blanks where the account
                     number belongs. --}}
                <section class="rounded-2xl border border-ink-200 p-5">
                    <h2 class="font-display text-lg font-semibold text-ink-900">How will you pay?</h2>
                    <p class="mt-1 text-sm text-ink-600">
                        A member of staff checks every payment. Your booking is confirmed once they have.
                    </p>

                    <div class="mt-4 space-y-3">
                        @foreach ($methods as $index => $method)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-ink-300 p-4 transition-colors duration-150 has-checked:border-brand-600 has-checked:bg-brand-50">
                                <input type="radio" name="payment_method" value="{{ $method->code->value }}"
                                       @checked(old('payment_method', $methods->first()?->code->value) === $method->code->value)
                                       class="size-4 border-ink-400 text-brand-600 focus:ring-brand-600">
                                <span class="flex-1">
                                    <span class="block font-semibold text-ink-900">{{ $method->label }}</span>
                                    <span class="mt-0.5 block text-sm text-ink-600">
                                        Vehicle held for {{ $method->hold_duration_hours }} hours while you pay.
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('payment_method')
                        <p class="mt-3 text-sm text-danger-600" role="alert">{{ $message }}</p>
                    @enderror
                </section>

                <section class="rounded-2xl border border-ink-200 p-5">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" name="terms" value="1" required
                               class="mt-1 size-4 rounded border-ink-400 text-brand-600 focus:ring-brand-600">
                        <span class="text-sm text-ink-700">
                            I accept the terms and conditions, including the refundable security deposit of
                            <strong class="tabular">{{ $money($quote->securityDepositAmount) }}</strong>
                            payable at the branch, and the insurance excess of
                            <strong class="tabular">{{ $money($quote->insuranceExcessAmount) }}</strong>.
                        </span>
                    </label>
                    @error('terms')
                        <p class="mt-2 text-sm text-danger-600" role="alert">{{ $message }}</p>
                    @enderror
                </section>
            </div>

            {{-- ── Summary ──────────────────────────────────────────────────── --}}
            <div class="lg:sticky lg:top-6 lg:self-start">
                <div class="overflow-hidden rounded-2xl border border-ink-800 bg-ink-900 text-white">
                    <div class="border-b border-white/10 p-5">
                        <p class="font-display text-lg font-semibold">{{ $class->name }}</p>
                        <p class="mt-0.5 text-sm text-ink-300">{{ $vehicle->make }} {{ $vehicle->model }}</p>
                        <p class="tabular mt-3 text-sm text-ink-200">
                            {{ $branch->name }}<br>
                            {{ $range->start->setTimezone($zone)->format('D j M, H:i') }}
                            &rarr;
                            {{ $range->end->setTimezone($zone)->format('D j M, H:i') }}
                        </p>
                    </div>

                    <dl class="space-y-2.5 p-5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-300">Hire, {{ $quote->chargeableDays }} {{ Str::plural('day', $quote->chargeableDays) }}</dt>
                            <dd class="tabular font-medium">{{ $money($quote->hireTotal) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-300">Damage waiver</dt>
                            <dd class="tabular font-medium">{{ $money($quote->insuranceTotal) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-white/10 pt-3">
                            <dt class="font-display text-base font-semibold">Hire total</dt>
                            <dd class="tabular font-display text-xl font-semibold">{{ $money($quote->grandTotal) }}</dd>
                        </div>
                    </dl>

                    <div class="border-t border-white/10 bg-veld-600/15 p-5 text-sm">
                        <p class="font-semibold text-veld-100">Plus a refundable deposit</p>
                        <p class="tabular mt-0.5 text-veld-100/85">
                            {{ $money($quote->securityDepositAmount) }} in cash at the branch, returned when
                            you bring the vehicle back.
                        </p>
                    </div>

                    <div class="p-5">
                        {{-- The most consequential button on the site. It stays
                             a single, unambiguous primary action with nothing
                             competing beside it. --}}
                        <button type="submit"
                                class="pressable w-full cursor-pointer rounded-xl bg-brand-500 px-4 py-3.5 font-display text-base font-semibold text-white [transition:transform_160ms_var(--ease-out-strong),background-color_160ms_ease] hover:bg-brand-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                            Reserve and get payment details
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

@endsection
