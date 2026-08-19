@extends('layouts.site')

@section('title', 'Terms of hire')
@section('description', 'The terms on which vehicles are hired: what the price includes, the refundable security deposit, the insurance excess, how and when to pay, and the cancellation policy.')

@php
    $money = fn (string|null $amount): string => $currency.' '.number_format((float) ($amount ?? '0'), 2);

    // Spec §15 figures nobody has decided are NEVER printed as a term. A term
    // is a contractual statement, and "an administration fee of ZMW 0.00
    // applies" is a promise the operator never made. The controller returns
    // null for an undecided setting; this is how the page says so.
    //
    // ⚠ THE WORDING IS "not yet set", NOT "confirmed before you pay".
    //
    // The badge is substituted into eight different sentences, so it has to
    // read correctly in all of them. "confirmed before you pay" produced "The
    // administration fee deducted from a refund is confirmed before you pay" —
    // which states the opposite of what it means: that the figure HAS been
    // settled. Found by reading the rendered page, not by a test.
    //
    // The promise that it will be confirmed in writing is made ONCE, in the
    // footnote, where it is a sentence of its own and cannot invert.
    $undecided = '<span class="rounded bg-ember-50 px-1.5 py-0.5 text-xs font-medium text-ember-800">not yet set</span>';
@endphp

@section('content')

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">

        <h1 class="font-display text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl">
            Terms of hire
        </h1>
        <p class="mt-3 leading-relaxed text-ink-600">
            These terms apply to every booking made on this site. The figures below are the
            ones the booking system itself uses, so what you read here is what you will be
            charged.
        </p>
        <p class="mt-2 text-sm text-ink-500">
            Last updated {{ $updatedAt->timezone(config('carhire.display_timezone'))->format('j F Y') }}.
        </p>

        {{-- ── What the price includes ──────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">1. What the price includes</h2>
            <div class="mt-3 space-y-3 leading-relaxed text-ink-700">
                <p>
                    The price shown in search results is the full cost of the hire. It includes
                    the mandatory damage waiver and does not change between search and checkout.
                </p>
                <p>
                    It does <strong>not</strong> include the refundable security deposit, which is
                    taken separately at the branch and returned to you, nor any charge arising
                    from damage, late return or a policy breach described below.
                </p>
            </div>
        </section>

        {{-- ── Deposits ─────────────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">2. The two deposits</h2>
            <div class="mt-3 space-y-3 leading-relaxed text-ink-700">
                <p>
                    <strong>The booking deposit</strong> is part payment of the hire itself. You may
                    pay it to reserve a vehicle and settle the balance later, or pay in full at the
                    outset — either way it comes off what you owe.
                    @if ($policy['deposit_percentage'] !== null)
                        It is <strong>{{ $policy['deposit_percentage'] }}%</strong> of the total hire cost.
                    @else
                        The proportion payable is {!! $undecided !!}.
                    @endif
                </p>
                <p>
                    <strong>The refundable security deposit</strong> is held against damage. It is
                    <em>not</em> part of the hire price. It is taken in cash at the branch when you
                    collect the vehicle and returned when you bring it back undamaged. The amount
                    depends on the class of vehicle:
                </p>
            </div>

            @if ($classes->isNotEmpty())
                <div class="mt-4 overflow-x-auto rounded-2xl border border-ink-200">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Security deposit and insurance excess by vehicle class</caption>
                        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-500">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Vehicle class</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Security deposit</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Insurance excess</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-200">
                            @foreach ($classes as $class)
                                <tr>
                                    <th scope="row" class="px-4 py-3 text-left font-medium text-ink-900">{{ $class->name }}</th>
                                    <td class="tabular px-4 py-3 text-right text-ink-700">{{ $money($class->security_deposit_amount) }}</td>
                                    <td class="tabular px-4 py-3 text-right text-ink-700">{{ $money($class->insurance_excess_amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-sm leading-relaxed text-ink-600">
                    An individual vehicle may carry a different deposit from the rest of its class.
                    The figure that applies to your booking is shown before you pay.
                </p>
            @else
                <p class="mt-4 rounded-xl border border-dashed border-ink-300 bg-ink-50 px-4 py-3 text-sm text-ink-600">
                    No vehicle classes are currently offered for hire.
                </p>
            @endif
        </section>

        {{-- ── Insurance ────────────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">3. Insurance and the excess</h2>
            <p class="mt-3 leading-relaxed text-ink-700">
                A damage waiver is included in every hire and is already in the price you are
                shown. It is not optional and cannot be removed. It limits, but does not
                eliminate, what you owe if the vehicle is damaged: you remain liable for the
                excess shown in the table above, per incident.
            </p>
        </section>

        {{-- ── Paying ───────────────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">4. Paying for your hire</h2>
            <div class="mt-3 space-y-3 leading-relaxed text-ink-700">
                @if ($paymentMethods->isNotEmpty())
                    <p>
                        Payment is accepted by
                        {{ $paymentMethods->pluck('label')->join(', ', ' and ') }}.
                    </p>
                @endif
                <p>
                    <strong>No payment is taken automatically and no card is charged.</strong> You are
                    given a payment reference and instructions, and a member of staff checks that
                    the money has arrived. Submitting proof of payment is not the same as your
                    booking being confirmed — your booking is confirmed only when a member of
                    staff has verified the payment.
                </p>
                <p>
                    Every booking has a payment deadline, shown to you when you book. If the
                    deadline passes without payment, the booking is cancelled and the vehicle
                    released.
                    @if ($policy['deadline_margin_hours'] !== null)
                        A deadline never falls later than {{ $policy['deadline_margin_hours'] }}
                        {{ Str::plural('hour', $policy['deadline_margin_hours']) }} before collection.
                    @endif
                </p>
                @if ($policy['short_notice_hours'] !== null)
                    <p>
                        For a collection less than {{ $policy['short_notice_hours'] }}
                        {{ Str::plural('hour', $policy['short_notice_hours']) }} away, payment is taken
                        at the branch and <strong>no vehicle is held for you</strong> — availability is
                        first come, first served at the counter.
                    </p>
                @endif
            </div>
        </section>

        {{-- ── Cancellation ─────────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">5. Cancelling, and refunds</h2>
            <div class="mt-3 space-y-3 leading-relaxed text-ink-700">
                @if ($policy['cancellation_notice_hours'] !== null)
                    <p>
                        Cancel more than <strong>{{ $policy['cancellation_notice_hours'] }}
                        {{ Str::plural('hour', $policy['cancellation_notice_hours']) }}</strong> before
                        collection and you are refunded what you have paid, less an administration
                        fee. Cancel inside that window and the booking deposit is forfeited.
                    </p>
                @else
                    <p>
                        The notice required to cancel with a refund is {!! $undecided !!}.
                    </p>
                @endif
                <p>
                    The administration fee deducted from a refund is
                    @if ($policy['admin_fee'] !== null)
                        <strong class="tabular">{{ $money($policy['admin_fee']) }}</strong>.
                    @else
                        {!! $undecided !!}.
                    @endif
                </p>
                <p>
                    Refunds are approved by a second member of staff and paid back by the method
                    you used, or in cash at the branch. A refund is never paid twice.
                </p>
            </div>
        </section>

        {{-- ── Using the vehicle ────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">6. Using the vehicle</h2>
            <dl class="mt-3 space-y-4">
                <div>
                    <dt class="font-semibold text-ink-900">Fuel</dt>
                    <dd class="mt-1 leading-relaxed text-ink-700">
                        @if ($policy['fuel_policy'] === 'full_to_full')
                            The vehicle is supplied with a full tank and must be returned with a full
                            tank. Any shortfall is charged.
                        @elseif ($policy['fuel_policy'] !== null)
                            {{ Str::of((string) $policy['fuel_policy'])->replace('_', ' ')->ucfirst() }}.
                        @else
                            The fuel policy is {!! $undecided !!}.
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-ink-900">Mileage</dt>
                    <dd class="mt-1 leading-relaxed text-ink-700">
                        @if ($policy['mileage_policy'] === 'unlimited')
                            Mileage is unlimited.
                        @elseif ($policy['mileage_policy'] !== null)
                            {{ Str::of((string) $policy['mileage_policy'])->replace('_', ' ')->ucfirst() }}.
                        @else
                            The mileage policy is {!! $undecided !!}.
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-semibold text-ink-900">Returning late</dt>
                    <dd class="mt-1 leading-relaxed text-ink-700">
                        @if ($policy['late_return_charge'] !== null)
                            A late return is charged at
                            <strong class="tabular">{{ $money($policy['late_return_charge']) }}</strong> per hour.
                        @else
                            The charge for a late return is {!! $undecided !!}.
                        @endif
                        Returning a vehicle late also risks the next customer's booking, so please
                        telephone the branch if you are delayed.
                    </dd>
                </div>
            </dl>
        </section>

        {{-- ── Who may drive ────────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">7. Who may drive</h2>
            <ul class="mt-3 space-y-2 leading-relaxed text-ink-700">
                <li>
                    @if ($policy['minimum_driver_age'] !== null)
                        Drivers must be at least <strong>{{ $policy['minimum_driver_age'] }}</strong> years old.
                    @else
                        The minimum age to drive is {!! $undecided !!}.
                    @endif
                </li>
                <li>
                    @if ($policy['minimum_licence_years'] !== null)
                        You must have held a full driving licence for at least
                        <strong>{{ $policy['minimum_licence_years'] }}</strong>
                        {{ Str::plural('year', $policy['minimum_licence_years']) }}.
                    @else
                        The minimum time you must have held a licence is {!! $undecided !!}.
                    @endif
                </li>
                <li>
                    @if ($policy['foreign_licence_accepted'] === true)
                        Foreign driving licences are accepted.
                    @elseif ($policy['foreign_licence_accepted'] === false)
                        Foreign driving licences are not accepted.
                    @else
                        Whether a foreign licence is accepted is {!! $undecided !!}.
                    @endif
                </li>
                <li>
                    Identification and a driving licence must be produced at the branch before a
                    vehicle is released, and the security deposit must be paid.
                </li>
            </ul>
        </section>

        {{-- ── Collection ───────────────────────────────────────────────────── --}}
        <section class="mt-10">
            <h2 class="font-display text-xl font-semibold text-ink-900">8. Collection and return</h2>
            <p class="mt-3 leading-relaxed text-ink-700">
                Vehicles are collected and returned in person at a branch. A vehicle is normally
                returned to the branch it was collected from; one-way hires are possible by
                arrangement with staff.
            </p>

            @if ($branches->isNotEmpty())
                <ul class="mt-4 space-y-2 text-sm text-ink-700">
                    @foreach ($branches as $branch)
                        <li class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium text-ink-900">{{ $branch->name }}</span>
                            <span class="text-ink-500">{{ $branch->city }}</span>
                            @if ($branch->openingHoursLabel())
                                <span class="tabular text-ink-600">{{ $branch->openingHoursLabel() }}</span>
                            @else
                                {{-- §15.8 again. Nothing invented. --}}
                                <span class="text-ink-500">hours not published</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-sm">
                    <a href="{{ route('locations') }}"
                       class="rounded font-medium text-brand-700 underline decoration-brand-300 underline-offset-2 hover:decoration-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                        Addresses and telephone numbers
                    </a>
                </p>
            @endif
        </section>

        {{-- ── The honest footer ────────────────────────────────────────────── --}}
        <section class="mt-12 rounded-2xl border border-ink-200 bg-ink-50 p-6">
            <h2 class="font-display text-base font-semibold text-ink-900">About these terms</h2>
            <p class="mt-2 text-sm leading-relaxed text-ink-600">
                Every figure on this page is read directly from the booking system, so it cannot
                disagree with what you are charged. Where a term is marked
                <span class="rounded bg-ember-50 px-1.5 py-0.5 text-xs font-medium text-ember-800">not yet set</span>,
                that figure has not been decided yet and will be stated to you in writing before
                any payment is taken. Nothing is invented to fill the gap.
            </p>
        </section>
    </div>

@endsection
