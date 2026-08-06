<?php

namespace App\Services\Import;

use App\Services\Spreadsheet\CellValue;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use Illuminate\Support\Carbon;

/**
 * The Noon PO header, read off the metadata tab (§Q, M8).
 *
 * Every Noon workbook carries four tabs and one of them is the PO itself — and its NAME
 * is the PO number, which is how it is found: it is the tab that is not Short Titles,
 * Packing List or Picking List. That is the only reliable identifier. The filename is
 * "Al Samha NOONAUH01G (AUH01G) 287285145169960_V1 - Final.xlsx", which §T is explicit
 * about not trusting, and the tab carries no header row of its own.
 *
 * The fields sit in a LABEL/VALUE grid rather than a table — "P.O No :" in column 8 with
 * its value in column 9, "Partner Name :" in column 1 with its value in column 3 — and
 * the labels move between column blocks. So nothing here is read from a coordinate. Each
 * field is found by scanning the top of the sheet for its label and taking the first
 * non-empty cell to the right of it, which survives Noon shifting the block again.
 *
 * The same tab then repeats the Packing List below the header block, with a "Sub Total"
 * row at the bottom. We take the SUB TOTAL as a free cross-check on our own row
 * arithmetic and nothing more — the order itself is read from the Packing List tab,
 * where it is a clean table.
 */
class NoonPoMetadata
{
    /** The three tabs whose names are fixed. Whatever is left is the PO tab. */
    public const KNOWN_TABS = ['Short Titles', 'Packing List', 'Picking List'];

    private function __construct(
        public readonly ?string $sheetName,
        public readonly ?string $poNumber,
        public readonly ?string $partnerName,
        public readonly ?string $shipToUnit,
        public readonly ?string $shipToAddress,
        public readonly ?string $currency,
        public readonly ?Carbon $orderDate,
        public readonly ?Carbon $approvalDate,
        public readonly ?Carbon $estimatedDeliveryDate,
        public readonly ?string $vatNumber,
        public readonly ?string $partnerCode,
        public readonly ?int $subTotalUnits,
        public readonly ?float $grandTotal,
    ) {}

    /**
     * Which tab is the PO? The one that is not one of the three known names.
     *
     * Falls back to a tab whose name is all digits, so a file that renames "Short Titles"
     * still resolves rather than failing on a technicality.
     */
    public static function findSheetName(Workbook $workbook): ?string
    {
        $known = array_map(fn ($n) => strtolower($n), self::KNOWN_TABS);

        foreach ($workbook->sheetNames() as $name) {
            if (! in_array(strtolower(trim($name)), $known, true)) {
                return $name;
            }
        }

        foreach ($workbook->sheetNames() as $name) {
            if (preg_match('/^\d{6,}$/', trim($name))) {
                return $name;
            }
        }

        return null;
    }

    public static function readFrom(Workbook $workbook): self
    {
        $sheetName = self::findSheetName($workbook);

        if ($sheetName === null) {
            return self::empty();
        }

        $sheet = $workbook->sheet($sheetName);

        // The label grid lives in the top block, above the repeated packing table.
        $labels = self::labelIndex($sheet, maxRow: 15);

        $poNumber = self::valueFor($labels, 'p o no', 'po no', 'po number');

        // The tab is NAMED for the PO, so its own name is the better answer when the
        // label block has been reshuffled or the cell left blank.
        if ($poNumber === null && preg_match('/^\d{6,}$/', trim($sheetName))) {
            $poNumber = trim($sheetName);
        }

        [$subTotalUnits, $grandTotal] = self::readTotals($sheet);

        return new self(
            sheetName: $sheetName,
            poNumber: $poNumber,
            partnerName: self::valueFor($labels, 'partner name'),
            shipToUnit: self::valueFor($labels, 'pu'),
            shipToAddress: self::valueFor($labels, 'ship to'),
            currency: self::valueFor($labels, 'currency'),
            orderDate: self::dateFor($labels, 'date'),
            approvalDate: self::dateFor($labels, 'approval date'),
            estimatedDeliveryDate: self::dateFor($labels, 'estimated delivery date'),
            vatNumber: self::valueFor($labels, 'vat no', 'vat number'),
            partnerCode: self::valueFor($labels, 'partner code'),
            subTotalUnits: $subTotalUnits,
            grandTotal: $grandTotal,
        );
    }

    public function isUsable(): bool
    {
        return $this->poNumber !== null;
    }

    /**
     * Ship-to, as one readable line. Noon's "PU" is the pick-up unit ("Noon Supermall -
     * UAE"); the Ship To cell carries the warehouse and its address. The FC code screens
     * group by is the short unit code out of the address, when there is one.
     */
    public function fulfilmentCentre(): ?string
    {
        // "LE1AZSSSOAE Tariaq Bedon Esm - KHIA 8 - Al Ma..." - the code is the first token.
        if ($this->shipToAddress !== null
            && preg_match('/^([A-Z0-9]{6,})\b/', trim($this->shipToAddress), $m)) {
            return $m[1];
        }

        return $this->shipToUnit;
    }

    /**
     * Every label in the top block, mapped to the first non-empty value to its right.
     *
     * @return array<string, array{text: ?string, raw: mixed}>
     */
    private static function labelIndex(Sheet $sheet, int $maxRow): array
    {
        $labels = [];
        $maxColumn = $sheet->highestColumn();
        $maxRow = min($maxRow, $sheet->highestRow());

        for ($row = 1; $row <= $maxRow; $row++) {
            $cells = $sheet->row($row, $maxColumn);

            foreach ($cells as $column => $value) {
                $text = CellValue::asText($value);

                if ($text === null || ! str_contains($text, ':')) {
                    continue;
                }

                // "Partner Name :" -> "partner name". The colon is what marks a cell as a
                // label rather than data, and Noon is inconsistent about the space before it.
                $key = self::normalise(rtrim(trim($text), ':'));

                if ($key === '' || isset($labels[$key])) {
                    continue;
                }

                // The value is the next non-empty cell along the row. It is column+2 for
                // the left block and column+1 for the right one, so neither is assumed.
                for ($next = $column + 1; $next <= $maxColumn; $next++) {
                    $candidate = $cells[$next] ?? null;
                    $candidateText = CellValue::asText($candidate);

                    if ($candidateText !== null && $candidateText !== '') {
                        // A label immediately followed by another label means this one
                        // has no value at all - do not steal the next field's answer.
                        if (str_contains($candidateText, ':')) {
                            break;
                        }

                        $labels[$key] = ['text' => $candidateText, 'raw' => $candidate];

                        break;
                    }
                }
            }
        }

        return $labels;
    }

    /** The sheet's own "Sub Total (AED)" units and "Grand Total (AED)" — a cross-check. */
    private static function readTotals(Sheet $sheet): array
    {
        $units = null;
        $grand = null;
        $maxColumn = $sheet->highestColumn();

        // Scan from the bottom: the totals block is the last thing before the terms.
        for ($row = $sheet->highestRow(); $row >= 1; $row--) {
            $cells = $sheet->row($row, $maxColumn);
            $first = self::normalise(CellValue::asText($cells[1] ?? null));

            if ($first === '') {
                continue;
            }

            if ($units === null && str_starts_with($first, 'sub total')) {
                foreach ($cells as $value) {
                    $int = CellValue::asInt($value);

                    if ($int !== null && $int > 0) {
                        $units = $int;

                        break;
                    }
                }
            }

            if ($grand === null && str_starts_with($first, 'grand total')) {
                $grand = self::lastNumeric($cells);
            }

            if ($units !== null && $grand !== null) {
                break;
            }
        }

        return [$units, $grand];
    }

    private static function lastNumeric(array $cells): ?float
    {
        $last = null;

        foreach ($cells as $value) {
            $decimal = CellValue::asDecimal($value);

            if ($decimal !== null) {
                $last = $decimal;
            }
        }

        return $last;
    }

    private static function valueFor(array $labels, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $found = $labels[self::normalise($key)] ?? null;

            if ($found !== null) {
                return $found['text'];
            }
        }

        return null;
    }

    private static function dateFor(array $labels, string ...$keys): ?Carbon
    {
        foreach ($keys as $key) {
            $found = $labels[self::normalise($key)] ?? null;

            if ($found !== null) {
                $date = CellValue::asDate($found['raw']);

                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private static function normalise(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function empty(): self
    {
        return new self(null, null, null, null, null, null, null, null, null, null, null, null, null);
    }
}
