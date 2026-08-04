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

    /**
     * A plausible Amazon bulk PO export.
     *
     * The defaults are the two-line PO the M3 tests are written around. Turnaround tests
     * pass their own lines and order date, because the whole KPI is measured from it.
     *
     * @param  array<int, array{0: string, 1: string, 2: int, 3: int}>|null  $lines  [po, asin, requested, accepted]
     */
    public static function amazonPo(?array $lines = null, string $orderDate = '2026-08-03', string $fc = 'DXB3'): self
    {
        $lines ??= [
            ['774FV9FB', 'B08TEST0001', 200, 180],
            ['774FV9FB', 'B08TEST0002', 100, 100],
        ];

        $rows = [[
            'PO', 'Vendor code', 'Order date', 'Status', 'Product name', 'ASIN',
            'External ID type', 'External ID', 'Requested quantity',
            'Accepted quantity', 'Ship-to location', 'Cost', 'Currency',
        ]];

        foreach ($lines as $index => [$po, $asin, $requested, $accepted]) {
            $rows[] = [$po, '1F6RD', $orderDate, 'Confirmed', 'Test product '.($index + 1), $asin,
                'EAN', '0634562947130', $requested, $accepted, $fc, 24.5, 'AED'];
        }

        return (new self)
            ->sheet('Instructions', [['Ignore this tab']])
            ->sheet('Line Items', $rows);
    }

    /**
     * A packing list in the interim layout, with the shipment name, date and lines the
     * test needs. The stage is chosen at upload (§J), so this one shape serves both -
     * the separately shifted FINAL layout has its own helper above.
     *
     * @param  array<int, array{0: string, 1: string, 2: int}>  $lines  [po, asin, qty]
     */
    public static function shipment(string $shipmentName, array $lines, ?string $shipmentDate = '2026-08-12'): self
    {
        $rows = [
            [null, null, null, 'Shipment Name: '.$shipmentName],
            [null, null, null, $shipmentDate === null ? null : 'Shipment Date: '.$shipmentDate],
            [],
            ['PO', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton', 'Unit Cost'],
        ];

        foreach ($lines as $index => [$po, $asin, $qty]) {
            $rows[] = [$po, $asin, '0634562947130', 'Test product', $qty, (string) ($index + 1), 10.0];
        }

        return (new self)->sheet('Simple List', $rows);
    }

    /**
     * The single-PO export: no PO column, no Ship-to, and the differing header names
     * the real file uses ("Quantity Requested", not "Requested quantity").
     */
    public static function singlePo(array $lines = [['B08TEST0001', 200, 180]]): self
    {
        $rows = [[
            'ASIN', 'External Id', 'External Id Type', 'Model Number', 'Title',
            'Expected date', 'Quantity Requested', 'Accepted quantity', 'Unit Cost',
        ]];

        foreach ($lines as [$asin, $requested, $accepted]) {
            $rows[] = [$asin, '0634562947130', 'UPC', '0634562947130', 'Test product',
                '08/20/2026', $requested, $accepted, 10.0];
        }

        return (new self)->sheet('Purchase Order', $rows);
    }

    /**
     * The FINAL packing-list layout: every column shifted right to make room for the
     * invoice number, and the banner moved from D1/D2 to F1/F2. Same header NAMES,
     * which is the whole point of matching by name.
     */
    public static function packingListFinal(string $shipmentName = '22161964743-Aug-02'): self
    {
        return (new self)->sheet('Simple List', [
            [null, null, null, null, null, 'Shipment Name: '.$shipmentName, null, null, 'Invoice value'],
            [null, null, null, null, null, 'Shipment Date: 03 Aug 2026 04:00 PM', null, null, 1590.0],
            [],
            ['PO', null, 'Invoice Number', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton', 'Unit Cost',
                'Match key', 'Lookup row', 'Line Value'],
            ['774FV9FB', 'BD-3269-', 'BD-3269-774FV9FB', 'B08TEST0001', '0634562947130',
                'Test product one', 90, '1-2', 10.0, '634562947130', 413, 900.0],
            ['774FV9FB', 'BD-3269-', 'BD-3269-774FV9FB', 'B08TEST0002', '0634562947131',
                'Test product two', 60, '3', 11.5, '634562947131', 175, 690.0],
        ]);
    }

    /** The cancellations template, as the team pastes it (tab literally "Sheet1"). */
    public static function cancellations(array $rows = [['774FV9FB', 'B08TEST0001', 180, 20]]): self
    {
        $sheet = [['PO Number', 'ASIN', 'External ID', 'Description',
            'Quantity Confirmed', 'Quantity Cancelled']];

        foreach ($rows as [$po, $asin, $confirmed, $cancelled]) {
            $sheet[] = [$po, $asin, '0634562947130', 'Test product', $confirmed, $cancelled];
        }

        return (new self)->sheet('Sheet1', $sheet);
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
