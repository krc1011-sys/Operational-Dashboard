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

    /*
     | How long a verified PIN stays valid, in minutes of IDLE time.
     |
     | Unlock-for-the-session (§Profitability, M7): entered once, it stays in until logout
     | or until nothing happens for this long. The window slides on any authenticated
     | request, not only on the money screens - see TouchMoneyPinSession - because the
     | thing being protected against is an unattended screen, and someone working in
     | another tab of the app is not that.
     */
    'money_pin_timeout' => (int) env('OPERON_MONEY_PIN_TIMEOUT', 15),

    // How many wrong PIN attempts are allowed before a temporary lockout.
    'money_pin_max_attempts' => (int) env('OPERON_MONEY_PIN_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Bundle components  (M8)
    |--------------------------------------------------------------------------
    |
    | Products that are never sold on their own: they go into a bundle, and the bundle is
    | what carries a price. Their channel row has a real COST against a selling price that
    | was never charged, so the engine computes a margin that is arithmetic over a fiction
    | - BD07903074 topped the losing-SKU list at -33.31%, a phantom loss for a product that
    | has never been sold at a loss because it has never been sold at all.
    |
    | Flagged products KEEP every cost and purchase figure everywhere. What the flag does
    | is hold them out of margin RANKINGS and loss watchlists, where a meaningless
    | percentage crowds out the real ones.
    |
    | This list is a STARTING POINT, applied when a product is first created by a master
    | import. The master grid's own toggle is the last word - a flag set or cleared by hand
    | is never overwritten by a later upload.
    |
    | Rolling a component's cost up into the bundle it belongs to is the real fix and is
    | Phase 2/3 work: it needs a bundle-to-component mapping the catalog does not carry.
    |
    */
    'bundle_components' => [
        'BD07903074',
    ],

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
    | Days of cover — the sell-through watchlists  (M9)
    |--------------------------------------------------------------------------
    |
    | Days of cover = stock on hand ÷ daily run rate. These thresholds are what turn
    | that number into a named list somebody can act on, and they are HERE rather than
    | in the engine because they are commercial judgement, not arithmetic: the right
    | overstock line for tissues on 60-day terms is not the right one for a seasonal SKU.
    |
    | Starting values, chosen against the real files:
    |
    |   overstock_days   90  — three months of stock. Amazon's own "Aged 90+ Days" column
    |                          uses the same horizon, so a SKU can reach the overstock
    |                          list either by our arithmetic or by Amazon's own statement,
    |                          and the two agree on what "too long" means.
    |   stockout_days    14  — under a fortnight of cover. Roughly the vendor lead time on
    |                          the real file (17.4 days on the biggest SKU), so this is
    |                          "we cannot restock before we run out".
    |   dead_stock_days  30  — stock that sold NOTHING in this many days of the window.
    |                          It has no run rate at all, so it can never appear on a
    |                          cover-based list; without this rule the worst overstock in
    |                          the catalog would be invisible.
    |
    */
    'cover' => [
        'overstock_days' => 90,
        'stockout_days' => 14,
        'dead_stock_days' => 30,
        // Ignore trivia: a SKU holding a handful of units is not an overstock problem.
        'min_units_to_flag' => 10,
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
