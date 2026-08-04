<?php

/**
 * OperON application configuration.
 *
 * Central place for the business rules that the blueprint (03_LOGIC_BLUEPRINT.md)
 * says must be switchable without touching code.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Launch upload lockdown  (blueprint §O — "LAUNCH RECONCILIATION")
    |--------------------------------------------------------------------------
    |
    | The full permission matrix gives Procurement and Warehouse the right to
    | upload their own file types. Karan's current-phase rule is that ALL
    | uploads are Admin-only until the team is onboarded.
    |
    | true  = every `upload-*` permission is granted to Admin only (launch state)
    | false = the full matrix applies (Procurement/Warehouse can upload)
    |
    | Flip to false in .env and re-run `php artisan db:seed --class=RolesAndPermissionsSeeder`
    | to switch the team's upload rights on.
    |
    */
    'uploads_admin_only' => env('OPERON_UPLOADS_ADMIN_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Money / margin PIN  (blueprint §S — "money = ADMIN ONLY, behind PIN")
    |--------------------------------------------------------------------------
    |
    | Screens showing cost, price, margin or net P&L require a second factor on
    | top of the role permission: a PIN entered once per session.
    |
    | CHANGE THIS in your .env file before real data goes in.
    |
    */
    'money_pin' => env('OPERON_MONEY_PIN', '1234'),

    // How long a verified PIN stays valid, in minutes, before it is asked for again.
    'money_pin_timeout' => (int) env('OPERON_MONEY_PIN_TIMEOUT', 30),

    // How many wrong PIN attempts are allowed before a temporary lockout.
    'money_pin_max_attempts' => (int) env('OPERON_MONEY_PIN_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Business benchmarks  (blueprint §L, §M)
    |--------------------------------------------------------------------------
    */
    'benchmarks' => [
        // Headline turnaround target: days from PO date to full fulfilment (§L).
        'turnaround_days' => 10,

        // Amazon's own scorecard thresholds, mirrored as our green/amber/red (§M).
        'fill_rate_target' => 95.0,   // percent — "In Full Delivery"
        'defect_rate_target' => 5.0,  // percent — Amazon's ≤5% defect target
        'confirmation_rate_target' => 80.0, // percent — Accepted ÷ Requested (§L)
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels  (blueprint §R — the channel dimension)
    |--------------------------------------------------------------------------
    |
    | Phase 1 in-scope channels. Tradeling / Noon Bulk / Noon SC stay dormant (§S).
    |
    */
    'channels' => [
        'amazon_retail' => 'Amazon Retail',
        'amazon_dfs' => 'Amazon DFS',
        'noon_retail' => 'Noon Retail',
    ],

];
