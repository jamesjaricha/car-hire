{{--
| "A member of staff has checked your payment."
|--------------------------------------------------------------------------
|
| Sent for part payments as well as full settlement. Somebody who has paid the
| 50% booking deposit has done exactly what was asked and needs to know it
| arrived — and what is still owed. Sending only on full settlement would leave
| the most common case in the whole flow silent.
--}}
<x-mail::message>
@if ($isFullySettled)
# Your booking is confirmed
@else
# We have received your payment
@endif

Hello {{ $booking->customer?->full_name ?? 'there' }},

A member of staff has checked your payment of
**{{ $currency }} {{ number_format((float) $amountConfirmed, 2) }}**
against booking **{{ $booking->reference }}**.

@if ($isFullySettled)
Nothing further is owed for the hire. Your vehicle is reserved for you.
@else
<x-mail::panel>
**Still to pay: {{ $currency }} {{ number_format((float) $balanceDue, 2) }}**

This is payable at the branch before you collect the vehicle.
</x-mail::panel>
@endif

## Your hire

- **Collecting:** {{ $booking->pickup_at?->timezone($zone)->format('D j M Y, H:i') }}
- **Returning:** {{ $booking->dropoff_at?->timezone($zone)->format('D j M Y, H:i') }}

## When you collect

Please bring your **driving licence** and **identification**. A refundable
security deposit is taken in cash at the branch and returned when you bring the
vehicle back undamaged — it is separate from the hire price.

<x-mail::button :url="route('booking.confirmation', ['reference' => $booking->reference])">
View your booking
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
