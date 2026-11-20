<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Markup
    |--------------------------------------------------------------------------
    |
    | What users pay on top of Pixelforge's cost, in basis points (2500 = 25%).
    |
    */

    'markup_basis_points' => (int) env('BILLING_MARKUP_BASIS_POINTS', 2500),

    /*
    |--------------------------------------------------------------------------
    | Credits
    |--------------------------------------------------------------------------
    |
    | Credits are US dollars. Packs are the amounts offered by "Buy credits"
    | and the signup credit is granted to every newly registered user.
    |
    */

    'credit_packs' => ['5.00', '10.00', '25.00', '50.00'],

    'signup_credit' => env('BILLING_SIGNUP_CREDIT', '1.00'),

    /*
    |--------------------------------------------------------------------------
    | Fairness
    |--------------------------------------------------------------------------
    |
    | Pixelforge only runs a few generations per account at once, shared by all
    | of our users, so a single user may only have this many in flight.
    |
    */

    'max_in_flight_per_user' => (int) env('BILLING_MAX_IN_FLIGHT_PER_USER', 5),

];
