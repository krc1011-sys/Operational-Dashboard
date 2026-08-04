<?php

namespace App\Services\Spreadsheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/**
 * A thin, defensive wrapper around PhpSpreadsheet.
 *
 * Two blueprint rules shape this class:
 *
 *  1. Files are read EXACTLY AS THE SOURCE SENDS THEM (§C, §K). That means real
 *     `.xls` (Excel 97-2003) as well as `.xlsx`, with no manual conversion, and no
 *     asking the user to flatten formulas first.
 *  2. The packing list's Simple List cells are formulas pointing at a hidden tab, so
 *     we take each cell's CACHED CALCULATED VALUE. Reading in data-only mode does that
 *     for us; CellValue adds a fallback for the rare case where a cache is missing.
 *
 * Images are never read (data-only mode), which is also why the ext-gd requirement is
 * irrelevant to us.
 */
class Workbook
{
    private function __construct(
        private readonly Spreadsheet $spreadsheet,
        public readonly string $path,
    ) {}

    public static function open(string $path): self
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read the uploaded file at {$path}.");
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (ReaderException $e) {
            throw new RuntimeException(
                'This file could not be opened as a spreadsheet. It may be corrupt, '
                .'password protected, or not really an Excel file.',
                previous: $e
            );
        }

        // Values only: skips styles, images and charts. Formula cells come back as
        // their last calculated value, which is exactly what the packing lists need.
        $reader->setReadDataOnly(true);

        return new self($reader->load($path), $path);
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return $this->spreadsheet->getSheetNames();
    }

    /**
     * Find a sheet by name, ignoring case and surrounding whitespace.
     * Amazon and the packing tool are not consistent about either.
     */
    public function findSheetName(string ...$candidates): ?string
    {
        $available = $this->sheetNames();

        foreach ($candidates as $candidate) {
            foreach ($available as $name) {
                if (strcasecmp(trim($name), trim($candidate)) === 0) {
                    return $name;
                }
            }
        }

        return null;
    }

    public function hasSheet(string ...$candidates): bool
    {
        return $this->findSheetName(...$candidates) !== null;
    }

    public function sheet(?string $name = null): Sheet
    {
        $worksheet = $name === null
            ? $this->spreadsheet->getSheet(0)
            : $this->spreadsheet->getSheetByName($this->findSheetName($name) ?? $name);

        if ($worksheet === null) {
            throw new RuntimeException("The sheet \"{$name}\" is not in this file. "
                .'Found: '.implode(', ', $this->sheetNames()).'.');
        }

        return new Sheet($worksheet);
    }

    /** Free the memory a large workbook holds. */
    public function close(): void
    {
        $this->spreadsheet->disconnectWorksheets();
    }
}
