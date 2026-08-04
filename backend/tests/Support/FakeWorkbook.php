<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Builds throwaway spreadsheets that imitate the real files, so the upload framework can
 * be tested end to end before the genuine samples arrive at M3.
 *
 * These are deliberately MINIMAL stand-ins. They prove the machinery works - right tab,
 * header found by name, wrong file rejected, .xls readable - not that the real Amazon
 * layouts are handled. That is exactly what the M3 checkpoint verifies.
 */
class FakeWorkbook
{
    private Spreadsheet $spreadsheet;

    private int $sheetIndex = 0;

    public function __construct()
    {
        $this->spreadsheet = new Spreadsheet;
        $this->spreadsheet->removeSheetByIndex(0);
    }

    /**
     * Add a sheet whose rows are given as a list of arrays, starting at row 1.
     * A null row leaves that row blank - handy for the sell-out file's metadata row.
     *
     * @param  array<int, array<int, mixed>|null>  $rows
     */
    public function sheet(string $title, array $rows): self
    {
        $sheet = $this->spreadsheet->createSheet($this->sheetIndex++);
        $sheet->setTitle($title);

        foreach ($rows as $rowIndex => $cells) {
            if ($cells === null) {
                continue;
            }

            foreach ($cells as $colIndex => $value) {
                if ($value === null) {
                    continue;
                }

                // Identifiers stay text so leading zeros survive, as in the real files.
                is_string($value)
                    ? $sheet->setCellValueExplicit([$colIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING)
                    : $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        return $this;
    }

    /** Write to a temp file and return its path. Extension picks the format. */
    public function write(string $extension = 'xlsx'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'operon_test_').'.'.$extension;

        $writer = $extension === 'xls'
            ? new XlsWriter($this->spreadsheet)
            : new XlsxWriter($this->spreadsheet);

        $writer->save($path);
        $this->spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** A plausible Amazon bulk PO export. */
    public static function amazonPo(): self
    {
        return (new self)
            ->sheet('Instructions', [['Ignore this tab']])
            ->sheet('Line Items', [
                ['PO', 'Vendor code', 'Order date', 'Status', 'Product name', 'ASIN',
                    'External ID type', 'External ID', 'Requested quantity',
                    'Accepted quantity', 'Ship-to location', 'Cost', 'Currency'],
                ['774FV9FB', '1F6RD', '2026-08-03', 'Confirmed', 'Test product one', 'B08TEST0001',
                    'EAN', '0634562947130', 200, 180, 'DXB3', 24.5, 'AED'],
                ['774FV9FB', '1F6RD', '2026-08-03', 'Confirmed', 'Test product two', 'B08TEST0002',
                    'UPC', '634562947131', 100, 100, 'DXB3', 12.0, 'AED'],
            ]);
    }

    /** A plausible interim packing list: banner rows, header on row 4, a carton total. */
    public static function packingList(): self
    {
        return (new self)
            ->sheet('Short Titles', [['ignored']])
            ->sheet('Packing List', [['ignored']])
            ->sheet('Simple List', [
                [null, null, null, 'Shipment Name: Aug-01-22161389743'],
                [null, null, null, 'Shipment Date: 2026-08-12'],
                [],
                ['PO', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton', 'Unit Cost'],
                ['774FV9FB', 'B08TEST0001', '0634562947130', 'Test product one', 100, '1', 24.5],
                ['774FV9FB', 'B08TEST0001', '0634562947130', 'Test product one', 80, '2', 24.5],
                [null, null, null, 'Carton total', 180, '1-2', null],
                ['774FV9FB', 'B08TEST0002', '634562947131', 'Test product two', 60, '3', 12.0],
            ]);
    }
}
