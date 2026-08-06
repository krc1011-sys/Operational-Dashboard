<?php

namespace App\Support;

/**
 * Barcode normalisation — the join key between Noon's two tabs (§Q, M8).
 *
 * Noon's Packing List (the order) and Picking List (what was delivered) are joined on
 * BARCODE, not on NIN, and the two tabs do not write it the same way. The Packing List's
 * GTIN column has `642135123720`; the Picking List's Barcodes column has `0642135123720`.
 * Same product, same barcode, one leading zero apart — a GTIN-13 written into a
 * GTIN-14 field, or the reverse.
 *
 * Joining on the raw strings silently matches NOTHING for those rows, and a silent
 * non-match here is the worst possible failure: an unmatched picking row looks exactly
 * like a line that was never delivered, so the fill rate would collapse and the report
 * would be confidently wrong rather than obviously broken.
 *
 * So every barcode is reduced to its significant digits before it is compared or stored
 * as a key. The ORIGINAL string is kept alongside for display, because the leading zero
 * is real to Noon and their support desk will ask for it exactly as printed.
 */
class Barcode
{
    /**
     * The comparison form: digits only, leading zeros stripped.
     *
     * Excel is the reason this cannot just be `ltrim($s, '0')`. A barcode read out of a
     * numeric cell arrives as a float and can stringify as "6.4213512372E+11" or
     * "642135123720.0", so anything that is not a digit goes first.
     */
    public static function key(string|int|float|null $barcode): ?string
    {
        if ($barcode === null) {
            return null;
        }

        // A numeric cell has already lost its leading zero in Excel; formatting it as a
        // plain integer avoids scientific notation putting an "E" in the middle of it.
        if (is_float($barcode) || is_int($barcode)) {
            $barcode = number_format((float) $barcode, 0, '.', '');
        }

        $digits = preg_replace('/\D+/', '', (string) $barcode) ?? '';
        $digits = ltrim($digits, '0');

        return $digits === '' ? null : $digits;
    }

    /** The form to show a person: trimmed, but with the leading zero Noon printed. */
    public static function display(string|int|float|null $barcode): ?string
    {
        if ($barcode === null) {
            return null;
        }

        if (is_float($barcode) || is_int($barcode)) {
            $barcode = number_format((float) $barcode, 0, '.', '');
        }

        $barcode = trim((string) $barcode);

        return $barcode === '' ? null : $barcode;
    }

    /** Do two barcodes refer to the same product, however each was written? */
    public static function matches(string|int|float|null $a, string|int|float|null $b): bool
    {
        $left = self::key($a);

        return $left !== null && $left === self::key($b);
    }
}
