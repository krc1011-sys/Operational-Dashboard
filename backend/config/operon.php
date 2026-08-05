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

    /*
    |--------------------------------------------------------------------------
    | Net margin  (blueprint §S — M6)
    |--------------------------------------------------------------------------
    */

    // VAT, for turning an RSP that includes it into one that does not. 5% in the UAE.
    // Sits here rather than in the engine so KSA's 15% is a config change, not a code one.
    'vat_rate' => 0.05,

    /*
    |--------------------------------------------------------------------------
    | Front and back margin  (the marketplace's cut)
    |--------------------------------------------------------------------------
    |
    | WE ARE A VENDOR, NOT A SELLER-CENTRAL SELLER. Amazon Vendor Central, Amazon DFS
    | and Noon Retail all BUY from us. What the marketplace keeps is:
    |
    |   front margin - taken off the retail price to reach the invoice/PO value
    |   back  margin - taken off the invoice to reach what we actually bank
    |
    |     Invoice        = RSP ex VAT x invoice_pct_of_rsp
    |     Net receivable = Invoice     x net_pct_of_invoice
    |
    | The Seller-Central fee columns in the master sheet - fulfilment, referral,
    | warehouse/storage, category and other fees - DO NOT APPLY to us and are never
    | deducted. They are stored because the file carries them, and ignored.
    |
    | These are DEFAULTS. Each product-channel row stores its own two rates, taken from
    | the file where it states them, because they genuinely vary: 151 Noon rows - every
    | one of them Category = FnB - keep 0.80 of the invoice rather than 0.78.
    |
    | Amazon: 0.9019 x 0.78 = 0.703482 -> the marketplace takes 29.65%
    | Noon:   0.98   x 0.78 = 0.7644   -> the marketplace takes 23.56%
    | Noon food: 0.98 x 0.80 = 0.784   -> the marketplace takes 21.60%
    */
    'margin' => [
        'amazon_retail' => ['invoice_pct_of_rsp' => 0.9019, 'net_pct_of_invoice' => 0.78],
        'amazon_dfs' => ['invoice_pct_of_rsp' => 0.9019, 'net_pct_of_invoice' => 0.78],
        'noon_retail' => ['invoice_pct_of_rsp' => 0.98, 'net_pct_of_invoice' => 0.78],
    ],

    /*
     | The §S cost rule, as a switch rather than a comment.
     |
     | 'latest'   — a product has several suppliers; take the most recent price. The
     |              interim rule, in force now, and shown as provisional wherever a
     |              margin built on it appears.
     | 'weighted' — the weighted average across supplier POs. Needs the Supplier-PO
     |              uploads that arrive in Phase 3 (§N); selecting it before then would
     |              have nothing to average.
     */
    'cost_basis' => 'latest',

];
