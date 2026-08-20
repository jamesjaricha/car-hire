{{--
| "We have your booking. Here is how to pay for it."
|--------------------------------------------------------------------------
|
| ⚠ THIS EMAIL MUST NEVER SAY THE BOOKING IS CONFIRMED. Spec §7.3: proof of
| payment does not confirm a booking, a person does. A customer who reads
| "confirmed" here does not send the money, and then wonders why their car is
| not waiting for them.
--}}
<x-mail::message>
# We have your booking

Hello {{ $booking->customer?->full_name ?? 'there' }},

Thank you for booking with us. **Your booking is not confirmed yet** — it is
confirmed once a member of staff has checked your payment.

## Your reference

<x-mail::panel>
{{ $booking->reference }}
</x-mail::panel>

Please quote this reference when you pay, and keep this email until you have
collected the vehicle.

## What to pay

**{{ $currency }} {{ number_format((float) $amountToPayNow, 2) }}**

@if ($deadlineAt)
Please pay by **{{ $deadlineAt->timezone($zone)->format('D j M Y, H:i') }}**.
If we have not received it by then, the booking is cancelled automatically and
the vehicle is released.
@endif

@if ($instructions !== '')
## How to pay

{{ $instructions }}
@endif

@if ($isShortNotice)
<x-mail::panel>
**Your collection is very soon, so no vehicle is being held for you.**
Availability is first come, first served at the branch. Please arrive as early
as you can, and telephone ahead if you are delayed.
</x-mail::panel>
@else
Your vehicle is held for you until the deadline above.
@endif

## Your hire

- **Collecting:** {{ $booking->pickup_at?->timezone($zone)->format('D j M Y, H:i') }}
- **Returning:** {{ $booking->dropoff_at?->timezone($zone)->format('D j M Y, H:i') }}

Remember that a refundable security deposit is taken in cash at the branch when
you collect, and returned when you bring the vehicle back. It is separate from
the hire price above.

{{-- `booking.confirmation` — reachable by reference without an account, which
     is how somebody returns to their payment instructions from this email.
     Spec §1.4 keeps guests guests. --}}
<x-mail::button :url="route('booking.confirmation', ['reference' => $booking->reference])">
View your payment instructions
</x-mail::button>

If you did not make this booking, please ignore this email — nothing has been
charged and no payment has been taken.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
