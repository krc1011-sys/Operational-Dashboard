<?php

/**
 * The currency lookup map.
 *
 * Money in OperON is always a pair: an amount and the currency code stored beside it on
 * the same row. Every money-bearing table carries its own `currency` column, filled from
 * the source file, so nothing here is assumed from context.
 *
 * ADDING A COUNTRY IS A DATA CHANGE, NOT A CODE CHANGE. We sell in the UAE today and
 * expect to enter KSA. Switching Saudi on means adding the 'SAR' block below and dropping
 * a `sar.svg` next to `aed.svg`. No screen, query, export or template is touched, because
 * none of them names a currency - they all ask this map.
 *
 * Each entry:
 *   name     - what a human calls it, used in tooltips and the master grid
 *   decimals - how many decimal places this currency is quoted in
 *   symbol   - an SVG filename in resources/svg/currency/, or null for code-only display
 *
 * A code with no entry here is not an error: the amount renders with its ISO code as
 * plain text ("USD 1,234.56"). An unexpected currency arriving in a file should be
 * visible, not a crash and not silently relabelled as dirhams.
 */
return [

    'default' => 'AED',

    'currencies' => [

        'AED' => [
            'name' => 'UAE Dirham',
            'decimals' => 2,
            /*
             * Displayed as the plain ISO code, "AED 1,234.50".
             *
             * The drawn dirham mark is still in the repo at resources/svg/currency/aed.svg
             * and still works; showing it is this one line:
             *
             *     'symbol' => 'aed.svg',
             *
             * Everything downstream follows either way - no screen, export or template
             * names a currency, so switching costs nothing but this value.
             */
            'symbol' => null,
        ],

        // KSA, when it switches on. Drop `sar.svg` into resources/svg/currency/ and
        // uncomment - that is the whole change.
        //
        // 'SAR' => [
        //     'name'     => 'Saudi Riyal',
        //     'decimals' => 2,
        //     'symbol'   => 'sar.svg',
        // ],

    ],
];
