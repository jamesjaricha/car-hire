<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Every instant is stored in UTC. This is the timezone those instants are
    | rendered in for customers and staff. Zambia observes UTC+2 year-round
    | with no daylight saving, which makes the conversion lossless.
    |
    */

    'display_timezone' => env('CARHIRE_DISPLAY_TIMEZONE', 'Africa/Lusaka'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The platform is single-currency at MVP. Monetary values are stored as
    | DECIMAL(12,2) and manipulated as strings via bcmath at the scale below.
    | They are never cast to float.
    |
    */

    'currency' => env('CARHIRE_CURRENCY', 'ZMW'),

    'money_scale' => 2,

    /*
    |--------------------------------------------------------------------------
    | Default Phone Region
    |--------------------------------------------------------------------------
    |
    | Region assumed when a customer types a number without a country code, so
    | that 097… is understood as a Zambian mobile. Numbers written with an
    | explicit country code are honoured as given — a substantial share of
    | customers are international visitors, and their numbers must survive
    | normalisation intact or every visit creates a duplicate customer.
    |
    */

    'default_phone_region' => env('CARHIRE_DEFAULT_PHONE_REGION', 'ZM'),

    /*
    |--------------------------------------------------------------------------
    | Booking References
    |--------------------------------------------------------------------------
    |
    | The prefix and padding for customer-facing booking references, e.g.
    | BR-00001. These are read aloud over the phone when a customer rings to
    | ask about a booking, so they are kept short and unambiguous.
    |
    | Changing the prefix after go-live does not renumber existing bookings; it
    | starts a separate sequence, since the counter is keyed by prefix.
    |
    */

    'booking_reference_prefix' => env('CARHIRE_BOOKING_REFERENCE_PREFIX', 'BR'),

    'booking_reference_padding' => 5,

    /*
    |--------------------------------------------------------------------------
    | Unmatched Payment References
    |--------------------------------------------------------------------------
    |
    | A payment against a booking takes that booking's reference plus a suffix:
    | BR-00001-1. Money that arrives without a booking — a mobile money receipt
    | nobody can attribute yet — has no such reference to build on, so it takes
    | its own series instead: UP-00001.
    |
    | A receipt KEEPS this reference when it is later matched to a booking. The
    | number staff wrote down when the money appeared must still find it
    | afterwards.
    |
    */

    'unmatched_payment_reference_prefix' => env('CARHIRE_UNMATCHED_PAYMENT_REFERENCE_PREFIX', 'UP'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Turnaround Buffer
    |--------------------------------------------------------------------------
    |
    | Minutes required between one hire ending and the next beginning, for
    | cleaning, refuelling and inspection. The authoritative value lives on
    | each vehicle class; this is only the fallback used when a class has
    | somehow not been given one.
    |
    */

    'fallback_turnaround_buffer_minutes' => 120,

    /*
    |--------------------------------------------------------------------------
    | Payment Method Feature Flags
    |--------------------------------------------------------------------------
    |
    | Spec §4 defines these as environment variables. They are read through
    | config rather than by calling env() at runtime: once configuration is
    | cached on a production server, env() returns null, which would silently
    | disable every payment method and stop the platform taking bookings.
    |
    | A method is offerable only if BOTH the flag here and the `enabled` column
    | on its row allow it. The column is the operator's switch, editable from
    | the admin panel; the flag is the deployment's switch, and it wins.
    |
    | Card methods stay false at MVP. They are shown greyed out as "Coming
    | Soon", and any request naming one is refused server-side.
    |
    */

    'payment_methods' => [
        'cash' => env('PAYMENT_METHOD_CASH_ENABLED', true),
        'bank_transfer' => env('PAYMENT_METHOD_BANK_TRANSFER_ENABLED', true),
        'mtn_momo' => env('PAYMENT_METHOD_MTN_MOMO_ENABLED', true),
        'airtel_money' => env('PAYMENT_METHOD_AIRTEL_MONEY_ENABLED', true),
        'debit_card' => env('PAYMENT_METHOD_DEBIT_CARD_ENABLED', false),
        'credit_card' => env('PAYMENT_METHOD_CREDIT_CARD_ENABLED', false),
    ],

];
