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

];
