<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Everything OperON knows about turning an amount into something a person can read.
 *
 * The rule this class exists to enforce: no screen, export or report ever names a
 * currency. They pass the code stored on the row and get back the right symbol, the right
 * number of decimals and the right text. Adding a country is then an entry in
 * config/currencies.php, not a hunt through the views for the string "AED".
 *
 * Two renderings, deliberately different:
 *   - html()  for screens - an inline SVG symbol beside the number.
 *   - plain() for CSV exports and log lines - the ISO code, because a spreadsheet cell
 *             cannot hold an SVG and "AED" is what Excel and QuickBooks both understand.
 */
class Currency
{
    /** Inlined SVG markup, keyed by filename, read once per request. */
    private static array $symbolCache = [];

    /**
     * The config block for a code, falling back to a code-only definition for a currency
     * we have never seen. An unknown code is displayed, not swallowed.
     */
    public static function definition(?string $code): array
    {
        $code = self::code($code);

        return config("currencies.currencies.{$code}", [
            'name' => $code,
            'decimals' => 2,
            'symbol' => null,
        ]);
    }

    /** Normalise a stored code; blank falls back to the configured default. */
    public static function code(?string $code): string
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : strtoupper((string) config('currencies.default', 'AED'));
    }

    /** Just the number, with this currency's decimal places. "1,234.56" */
    public static function amount(float|int|string|null $amount, ?string $code = null): string
    {
        return number_format((float) $amount, (int) self::definition($code)['decimals']);
    }

    /**
     * For CSV, filenames and anywhere a symbol cannot go: "AED 1,234.56".
     * The ISO code travels with the number so a column of figures is never ambiguous.
     */
    public static function plain(float|int|string|null $amount, ?string $code = null): string
    {
        return self::code($code).' '.self::amount($amount, $code);
    }

    /**
     * For screens: the symbol as an inline SVG, followed by the number.
     *
     * Currencies with no symbol file render their ISO code as text instead, which is how
     * an unexpected currency shows up honestly rather than borrowing the dirham mark.
     */
    public static function html(float|int|string|null $amount, ?string $code = null): HtmlString
    {
        $symbol = self::symbol($code);
        $number = e(self::amount($amount, $code));

        if ($symbol === null) {
            return new HtmlString(
                '<span class="whitespace-nowrap"><span class="opacity-70">'
                .e(self::code($code)).'</span> '.$number.'</span>'
            );
        }

        return new HtmlString(
            '<span class="whitespace-nowrap">'.$symbol->toHtml().' '.$number.'</span>'
        );
    }

    /**
     * The bare symbol, for table headers and anywhere a number is not attached.
     * Carries the ISO code as its accessible name, so a screen reader says "AED" rather
     * than describing a drawing.
     */
    public static function symbol(?string $code = null): ?HtmlString
    {
        $code = self::code($code);
        $file = self::definition($code)['symbol'] ?? null;

        if ($file === null) {
            return null;
        }

        $svg = self::readSymbol($file);

        if ($svg === null) {
            return null;
        }

        // The <svg> is ours, from the repo - never user input - so inlining it is safe,
        // and inlining is the point: it inherits colour and font size from its context.
        $svg = preg_replace(
            '/<svg /',
            // 0.85em rather than 1em: big enough that the two bars stay distinct at the
            // 14px table text we use, small enough not to shout over the number.
            '<svg role="img" aria-label="'.e($code).'" class="inline-block align-[-0.12em] w-[0.85em] h-[0.85em]" ',
            $svg,
            1
        );

        return new HtmlString($svg);
    }

    /** Read an SVG out of resources/svg/currency, stripping its authoring comments. */
    private static function readSymbol(string $file): ?string
    {
        if (array_key_exists($file, self::$symbolCache)) {
            return self::$symbolCache[$file];
        }

        $path = resource_path('svg/currency/'.$file);
        $svg = is_readable($path) ? trim((string) file_get_contents($path)) : null;

        if ($svg !== null) {
            $svg = trim(preg_replace('/<!--.*?-->/s', '', $svg));
        }

        return self::$symbolCache[$file] = ($svg !== null && $svg !== '' ? $svg : null);
    }

    /**
     * The one currency a set of rows is in, or null if they disagree.
     *
     * Totalling across currencies is meaningless, so callers that sum need to know. Today
     * everything is AED and this always returns 'AED'; the day a KSA file lands, a mixed
     * total says so on screen instead of quietly adding riyals to dirhams.
     *
     * @param  iterable<string|null>  $codes
     */
    public static function single(iterable $codes): ?string
    {
        $seen = [];

        foreach ($codes as $code) {
            if ($code !== null && trim((string) $code) !== '') {
                $seen[self::code($code)] = true;
            }
        }

        return match (count($seen)) {
            0 => self::code(null),
            1 => array_key_first($seen),
            default => null,
        };
    }
}
