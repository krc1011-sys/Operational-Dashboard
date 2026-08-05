<?php

namespace App\Services\Spreadsheet;

/**
 * Maps logical field names to the column numbers they actually occupy in THIS file.
 *
 * This is the "map columns by HEADER NAME, not position" rule from §K, applied to every
 * file type. It is what lets one parser handle both the interim and final packing lists
 * even though the final shifts every column right, and both Amazon PO export formats.
 *
 * Matching is forgiving about case, punctuation and spacing, because the same column is
 * variously "PO", "PO Number", "P.O No" and "Purchase Order" across the real files.
 */
class HeaderMap
{
    /** @param array<string,int> $columns normalised header => 1-based column number */
    private function __construct(private readonly array $columns) {}

    /**
     * Build from a header row as returned by Sheet::row().
     *
     * @param  array<int, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $columns = [];

        foreach ($row as $column => $value) {
            $header = self::normalise(CellValue::asText($value));

            // First occurrence wins: trailing helper columns sometimes repeat a name.
            if ($header !== '' && ! isset($columns[$header])) {
                $columns[$header] = $column;
            }
        }

        return new self($columns);
    }

    /**
     * Lower-case, strip punctuation, collapse whitespace.
     * "Ship-to location" and "Ship To Location" both become "ship to location".
     *
     * A percent sign becomes the word "pct" rather than being stripped, because it is
     * the only thing distinguishing two real columns in the master sheet: "Profit" and
     * "Profit %" sit side by side, and stripping the sign collapsed them onto one key -
     * so the percentage column could not be addressed by name at all. Aliases without
     * the sign still match, because "profit" is contained in "profit pct".
     */
    public static function normalise(?string $header): string
    {
        $header = strtolower(trim((string) $header));
        $header = str_replace('%', ' pct ', $header);
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/', ' ', $header) ?? $header);
    }

    /**
     * The column number for the first alias that matches, or null.
     * Aliases are tried in order, so list the most specific first.
     */
    public function column(string ...$aliases): ?int
    {
        foreach ($aliases as $alias) {
            $key = self::normalise($alias);

            if (isset($this->columns[$key])) {
                return $this->columns[$key];
            }
        }

        // Nothing exact - try a contains match, which rescues headers carrying units
        // or notes, e.g. "Accepted quantity (units)".
        foreach ($aliases as $alias) {
            $key = self::normalise($alias);

            foreach ($this->columns as $header => $column) {
                if ($key !== '' && str_contains($header, $key)) {
                    return $column;
                }
            }
        }

        return null;
    }

    public function has(string ...$aliases): bool
    {
        return $this->column(...$aliases) !== null;
    }

    /**
     * Pull a value out of a data row by header name.
     *
     * @param  array<int, mixed>  $row
     */
    public function value(array $row, string ...$aliases): mixed
    {
        $column = $this->column(...$aliases);

        return $column === null ? null : ($row[$column] ?? null);
    }

    public function text(array $row, string ...$aliases): ?string
    {
        return CellValue::asText($this->value($row, ...$aliases));
    }

    public function int(array $row, string ...$aliases): ?int
    {
        return CellValue::asInt($this->value($row, ...$aliases));
    }

    public function decimal(array $row, string ...$aliases): ?float
    {
        return CellValue::asDecimal($this->value($row, ...$aliases));
    }

    public function date(array $row, string ...$aliases)
    {
        return CellValue::asDate($this->value($row, ...$aliases));
    }

    /**
     * Which of these logical fields are missing from the file?
     *
     * @param  array<string, string[]>  $required  logical name => aliases
     * @return string[] the logical names that could not be found
     */
    public function missing(array $required): array
    {
        $missing = [];

        foreach ($required as $label => $aliases) {
            if (! $this->has(...$aliases)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /** How many of these fields were found - used to pick the real header row. */
    public function matchCount(array $fields): int
    {
        $count = 0;

        foreach ($fields as $aliases) {
            if ($this->has(...(array) $aliases)) {
                $count++;
            }
        }

        return $count;
    }

    /** The header names actually present, for a helpful rejection message. */
    public function headers(): array
    {
        return array_keys($this->columns);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }
}
