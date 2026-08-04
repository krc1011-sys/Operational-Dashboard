<?php

namespace App\Services\Spreadsheet;

use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet, read row by row.
 *
 * Rows come back as arrays keyed by 1-based column number, so a parser never has to
 * know that "Accepted quantity" happens to be column M today. Columns shift between
 * the interim and final packing lists, which is exactly why the blueprint insists on
 * mapping by header NAME rather than position (§K).
 */
class Sheet
{
    public function __construct(private readonly Worksheet $sheet) {}

    public function title(): string
    {
        return $this->sheet->getTitle();
    }

    public function highestRow(): int
    {
        return $this->sheet->getHighestDataRow();
    }

    public function highestColumn(): int
    {
        return Coordinate::columnIndexFromString($this->sheet->getHighestDataColumn());
    }

    /** A single cell by coordinate, e.g. the "Shipment Name:" banner in D1 or F1 (§K). */
    public function cell(string $coordinate): mixed
    {
        return CellValue::of($this->sheet->getCell($coordinate));
    }

    public function text(string $coordinate): ?string
    {
        return CellValue::asText($this->cell($coordinate));
    }

    /**
     * Scan a small block of cells for the first one containing a marker.
     * The Shipment Name/Date banner sits in D1/D2 on interim sheets but F1/F2 on
     * finals, so parsers look for the label rather than trusting a coordinate (§K).
     */
    public function findTextContaining(string $needle, int $maxRow = 4, int $maxCol = 12): ?string
    {
        for ($row = 1; $row <= $maxRow; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $value = CellValue::asText($this->cellAt($row, $col));

                if ($value !== null && stripos($value, $needle) !== false) {
                    return $value;
                }
            }
        }

        return null;
    }

    public function cellAt(int $row, int $column): mixed
    {
        return CellValue::of($this->sheet->getCell([$column, $row]));
    }

    /**
     * One row as [columnNumber => value].
     *
     * @return array<int, mixed>
     */
    public function row(int $row, ?int $maxColumn = null): array
    {
        $maxColumn ??= $this->highestColumn();
        $values = [];

        for ($col = 1; $col <= $maxColumn; $col++) {
            $values[$col] = $this->cellAt($row, $col);
        }

        return $values;
    }

    /**
     * Iterate data rows lazily, so a 3,000-row master sheet never sits in memory twice.
     *
     * @return Generator<int, array<int, mixed>>
     */
    public function rows(int $startRow, ?int $endRow = null): Generator
    {
        $endRow ??= $this->highestRow();
        $maxColumn = $this->highestColumn();

        for ($row = $startRow; $row <= $endRow; $row++) {
            yield $row => $this->row($row, $maxColumn);
        }
    }

    /** Is every cell in this row empty? Used to stop at the end of the data block. */
    public static function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
