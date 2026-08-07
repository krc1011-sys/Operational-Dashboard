<?php

namespace App\Services\Spreadsheet;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Turns one spreadsheet cell into a usable PHP value.
 *
 * The blueprint is emphatic on two points (§B, §K):
 *
 *  - Formula cells must yield their CACHED CALCULATED VALUE, and a missing cache must
 *    be handled gracefully rather than importing a blank.
 *  - Identifiers must never be silently mangled. A barcode like 0634562947130 read as
 *    a number loses its leading zero, which is precisely why barcodes are unreliable.
 *    `asText()` keeps identifiers as strings, including their leading zeros.
 */
class CellValue
{
    /** The raw value, with formulas resolved to their cached result. */
    public static function of(?Cell $cell): mixed
    {
        if ($cell === null) {
            return null;
        }

        if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
            return $cell->getValue();
        }

        // Data-only mode normally hands us the cached value already. If a file ever
        // arrives without one, fall back rather than importing a blank.
        $cached = $cell->getOldCalculatedValue();

        if ($cached !== null && $cached !== '') {
            return $cached;
        }

        try {
            return $cell->getCalculatedValue();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * An identifier or label as text: trimmed, with Excel's numeric mangling undone.
     *
     * Excel stores 634562947130 as a float, so a naive cast produces "6.3456294713E+11".
     * Identifiers must survive intact - see §B on why barcodes are display-only.
     */
    public static function asText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_float($value)) {
            // Whole floats are identifiers written as numbers - print them in full.
            $value = fmod($value, 1.0) === 0.0
                ? number_format($value, 0, '.', '')
                : (string) $value;
        }

        $text = trim((string) $value);

        // The master sheet is known to carry trailing whitespace and newlines in
        // ASINs; strip anything invisible rather than creating a near-duplicate (§S).
        $text = preg_replace('/[\x{00A0}\s]+/u', ' ', $text) ?? $text;

        return trim($text) === '' ? null : trim($text);
    }

    public static function asInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        // "1,250" and "1 250 units" both appear in exports.
        $digits = preg_replace('/[^0-9\-]/', '', (string) $value);

        return $digits === '' || $digits === '-' ? null : (int) $digits;
    }

    public static function asDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Strip currency symbols and thousands separators, e.g. "AED 14,240.95".
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $clean === '' || ! is_numeric($clean) ? null : (float) $clean;
    }

    /**
     * A date, whether the cell holds an Excel serial number or a text date.
     * Returns null rather than guessing when the value makes no sense as a date.
     */
    public static function asDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel serial dates: plausible range is roughly 1970-2100.
        if (is_numeric($value) && $value > 25000 && $value < 80000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse(trim((string) $value))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
