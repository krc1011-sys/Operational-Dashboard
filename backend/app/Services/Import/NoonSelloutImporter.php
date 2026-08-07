<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\InventorySnapshot;
use App\Models\SelloutRow;
use App\Models\SourceFile;
use App\Services\Spreadsheet\CellValue;
use App\Services\Spreadsheet\HeaderMap;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\Importer;
use App\Services\Upload\ImportResult;
use App\Services\Upload\ValidationResult;
use App\Support\Barcode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Noon sell-out AND stock, from the one workbook Noon sends (§Q, M9).
 *
 * Three tabs, read in one pass, because they only mean something together:
 *
 *   "Sellout L60"  order_pdate · pbarcode_canonical · brand_code · product_title ·
 *                  units_sold · GMV  — 60 days of DAILY sell-out, keyed by BARCODE.
 *   "SOH"          sku (the NIN) · psku · title_en · brand_code · psku_stock ·
 *                  L7_DRR · barcode — current stock, keyed by NIN.
 *   "Barcodes"     ZSKU → "Barcode w/o '0'" — Noon's own map between the two.
 *
 * ═══ WHY THE BARCODES TAB IS LOAD-BEARING ═══
 *
 * The sell-out half names products by barcode and the stock half names them by NIN.
 * Days of cover is stock ÷ run rate, so the two halves have to land on the same row, and
 * the only thing that can join them is that map. Without it Noon would have stock with
 * no velocity and velocity with no stock — every SKU would look either infinitely
 * covered or completely dead, and both readings would be wrong.
 *
 * The barcode is used to reach a NIN and stops there. It is never a cross-platform key
 * (§B): the mapping comes from Noon's own workbook, about Noon's own catalog, and the
 * NIN is what resolves to a product.
 *
 * ═══ THE THINGS THE FILE DOES TO ITSELF ═══
 *
 *  - Dates are EXCEL SERIALS. 46204 is 1 Jul 2026, 46239 is 5 Aug 2026.
 *  - Barcodes arrive as numbers, so a leading zero is already gone and a long one can
 *    stringify as 6.34E+11. Barcode::key reduces both to the same digits.
 *  - The SOH tab's barcode column is an XLOOKUP FORMULA filled down to row 1000, ~500
 *    rows past the data. Its cached value is what we read, and the loop stops at the
 *    first blank NIN rather than asking PhpSpreadsheet to evaluate 500 empty lookups.
 *  - `L7_DRR` is NOON'S OWN 7-day daily run rate. It is kept in preference to anything
 *    we could derive: Noon computed it from their complete order book, we would be
 *    computing it from a 60-day extract.
 */
class NoonSelloutImporter implements Importer
{
    /** The tab holding the current stock position. */
    private const SOH_TAB = 'SOH';

    /** Noon's own NIN ↔ barcode map. */
    private const BARCODES_TAB = 'Barcodes';

    /** How many consecutive empty rows end the SOH block. */
    private const BLANK_RUN = 5;

    public function __construct(private readonly SkuResolver $resolver) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $workbook = Workbook::open($path);

        try {
            // The map first: everything else is resolved through it.
            $map = $this->readBarcodeMap($workbook);
            $this->resolver->learnBarcodeMap($map);

            return DB::transaction(function () use ($workbook, $sourceFile, $validation, $map) {
                $sellOut = $this->readSellOut($workbook->sheet($validation->sheetName), $sourceFile, $validation);
                $stock = $this->readStock($workbook, $sourceFile, $sellOut['as_at']);

                return $this->result($sellOut, $stock, $map);
            });
        } finally {
            $workbook->close();
        }
    }

    // --- The map ----------------------------------------------------------

    /** @return array<string, string> barcode as written => NIN */
    private function readBarcodeMap(Workbook $workbook): array
    {
        if (! $workbook->hasSheet(self::BARCODES_TAB)) {
            return [];
        }

        $sheet = $workbook->sheet(self::BARCODES_TAB);
        $headers = HeaderMap::fromRow($sheet->row(1));
        $map = [];

        foreach ($sheet->rows(2) as $row) {
            $nin = $headers->text($row, 'zsku', 'nin', 'sku');
            // The tab's own spelling. Listed first so the scratch "Barcode" column
            // further right - a different lookup block Noon left in the file - cannot win.
            $barcode = $headers->text($row, "barcode w/o '0'", 'barcode w o 0', 'barcode');

            if ($nin !== null && $barcode !== null) {
                $map[$barcode] = $nin;
            }
        }

        return $map;
    }

    // --- Sell-out ---------------------------------------------------------

    /**
     * Daily sell-out by barcode, aggregated onto whichever key we could resolve.
     *
     * @return array<string, mixed>
     */
    private function readSellOut(Sheet $sheet, SourceFile $sourceFile, ValidationResult $validation): array
    {
        $headers = $validation->headers;

        $read = $skipped = 0;
        $units = 0;
        $gmv = 0.0;
        $minDate = null;
        $maxDate = null;
        $unmappedBarcodes = [];

        /** @var array<string, array<string, mixed>> "key|date" */
        $rows = [];

        foreach ($sheet->rows($validation->headerRow + 1) as $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $barcode = $headers->text($row, 'pbarcode canonical', 'barcode');
            $date = $headers->date($row, 'order pdate', 'order date');

            // A sell-out row with no day cannot join a daily series, and one with no
            // product cannot be attributed. Neither is guessable.
            if ($barcode === null || $date === null) {
                $skipped++;

                continue;
            }

            $barcodeKey = Barcode::key($barcode);
            $nin = $this->resolver->ninForBarcode($barcode);

            if ($nin === null) {
                $unmappedBarcodes[$barcodeKey] = Barcode::display($barcode);
            }

            [$productId] = $this->resolver->resolve(Marketplace::Noon, $nin, $barcode);

            /*
             * Two barcodes can map to one NIN - Noon re-lists a product - so the same
             * NIN can appear twice on one day. Aggregating before the write is what
             * stops that colliding on the one-row-per-SKU-per-day key.
             */
            $key = ($nin ?? $barcodeKey).'|'.$date->toDateString();

            $rows[$key] ??= [
                'sku_id' => $nin ?? $barcodeKey,
                'sku_id_type' => $nin === null ? 'barcode' : 'nin',
                'barcode' => Barcode::display($barcode),
                'barcode_key' => $barcodeKey,
                'product_id' => $productId,
                'title' => $headers->text($row, 'product title', 'title'),
                'brand' => $headers->text($row, 'brand code', 'brand'),
                'date' => $date->toDateString(),
                'units' => 0,
                'revenue' => 0.0,
            ];

            $lineUnits = (int) ($headers->int($row, 'units sold') ?? 0);
            $lineGmv = (float) ($headers->decimal($row, 'gmv') ?? 0);

            $rows[$key]['units'] += $lineUnits;
            $rows[$key]['revenue'] += $lineGmv;

            $units += $lineUnits;
            $gmv += $lineGmv;
            $minDate = $minDate === null ? $date->copy() : $minDate->min($date);
            $maxDate = $maxDate === null ? $date->copy() : $maxDate->max($date);
        }

        if ($rows === []) {
            throw new RuntimeException(
                'No dated sell-out rows could be read from the "Sellout L60" tab. Its dates '
                .'are Excel serials and its barcodes are in "pbarcode_canonical" - if both '
                .'columns are empty, this is not the sell-out export.'
            );
        }

        $written = $this->writeSellOut($rows, $sourceFile, $minDate, $maxDate);

        return [
            'read' => $read,
            'skipped' => $skipped,
            'imported' => $written,
            'unmatched' => count(array_filter($rows, fn ($r) => $r['product_id'] === null)),
            'units' => $units,
            'revenue' => round($gmv, 2),
            'from' => $minDate,
            'to' => $maxDate,
            // The stock tab carries no date of its own, so the workbook's most recent
            // sell-out day is what the snapshot is dated as at.
            'as_at' => $maxDate,
            'unmapped_barcodes' => array_values($unmappedBarcodes),
        ];
    }

    /** @param  array<string, array<string, mixed>>  $rows */
    private function writeSellOut(array $rows, SourceFile $sourceFile, ?Carbon $from, ?Carbon $to): int
    {
        // A snapshot of a rolling 60-day window: rebuild the window rather than merge
        // into it, so a product that stopped selling stops appearing.
        SelloutRow::query()
            ->where('channel', Channel::NoonRetail->value)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
            ->delete();

        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            SelloutRow::insert(array_map(fn (array $r) => [
                'marketplace' => Marketplace::Noon->value,
                'channel' => Channel::NoonRetail->value,
                'grain' => SelloutRow::GRAIN_DAY,
                'sku_id' => $r['sku_id'],
                'sku_id_type' => $r['sku_id_type'],
                'barcode' => $r['barcode'],
                'barcode_key' => $r['barcode_key'],
                'product_id' => $r['product_id'],
                'is_unmatched' => $r['product_id'] === null,
                'title' => $r['title'],
                'brand' => $r['brand'],
                'period_start' => $r['date'],
                'period_end' => $r['date'],
                'shipped_units' => $r['units'],
                'revenue' => round($r['revenue'], 4),
                'revenue_basis' => SelloutRow::BASIS_GMV,
                'currency' => config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => $now,
                'imported_by' => $sourceFile->uploaded_by,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return count($rows);
    }

    // --- Stock ------------------------------------------------------------

    /**
     * The SOH tab: current stock by NIN, with Noon's own 7-day run rate.
     *
     * @return array<string, mixed>
     */
    private function readStock(Workbook $workbook, SourceFile $sourceFile, ?Carbon $asAt): array
    {
        if (! $workbook->hasSheet(self::SOH_TAB)) {
            return ['rows' => 0, 'units' => 0, 'unmatched' => 0, 'missing_tab' => true];
        }

        $sheet = $workbook->sheet(self::SOH_TAB);
        $headers = HeaderMap::fromRow($sheet->row(1));

        $ninColumn = $headers->column('sku', 'nin', 'zsku');

        if ($ninColumn === null) {
            return ['rows' => 0, 'units' => 0, 'unmatched' => 0, 'missing_tab' => true];
        }

        $date = ($asAt ?? now())->toDateString();

        InventorySnapshot::query()
            ->where('channel', Channel::NoonRetail->value)
            ->whereDate('snapshot_date', $date)
            ->delete();

        $now = now();
        $rows = [];
        $units = 0;
        $unmatched = 0;
        $blankRun = 0;

        for ($rowNumber = 2; $rowNumber <= $sheet->highestRow(); $rowNumber++) {
            /*
             * The NIN cell is read on its own FIRST. The barcode column is an XLOOKUP
             * filled ~500 rows past the data, and reading a whole row would ask
             * PhpSpreadsheet to evaluate every one of those empty lookups.
             */
            $nin = CellValue::asText($sheet->cellAt($rowNumber, $ninColumn));

            if ($nin === null) {
                if (++$blankRun >= self::BLANK_RUN) {
                    break;
                }

                continue;
            }

            $blankRun = 0;
            $row = $sheet->row($rowNumber);

            $barcode = $headers->text($row, 'barcode');
            [$productId] = $this->resolver->resolve(Marketplace::Noon, $nin, $barcode);

            if ($productId === null) {
                $unmatched++;
            }

            $stock = $headers->int($row, 'psku stock', 'stock');
            $units += (int) $stock;

            $rows[] = [
                'marketplace' => Marketplace::Noon->value,
                'channel' => Channel::NoonRetail->value,
                'sku_id' => $nin,
                'sku_id_type' => 'nin',
                'barcode' => Barcode::display($barcode),
                'barcode_key' => Barcode::key($barcode),
                'product_id' => $productId,
                'is_unmatched' => $productId === null,
                'title' => $headers->text($row, 'title en', 'title'),
                'brand' => $headers->text($row, 'brand code', 'brand'),
                'snapshot_date' => $date,
                'soh_units' => $stock,
                // Noon's own figure, kept as given (§Q).
                'daily_run_rate' => $headers->decimal($row, 'l7 drr', 'drr'),
                'currency' => config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => $now,
                'imported_by' => $sourceFile->uploaded_by,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            InventorySnapshot::insert($chunk);
        }

        return [
            'rows' => count($rows),
            'units' => $units,
            'unmatched' => $unmatched,
            'date' => $date,
            'missing_tab' => false,
        ];
    }

    // --- Result -----------------------------------------------------------

    private function result(array $sellOut, array $stock, array $map): ImportResult
    {
        $warnings = [];

        if ($stock['missing_tab']) {
            $warnings[] = 'This workbook has no readable "SOH" tab, so Noon sell-out was '
                .'imported but Noon stock was not - which means Noon days of cover cannot be '
                .'calculated until a workbook carrying it is uploaded.';
        }

        if ($map === []) {
            $warnings[] = 'This workbook has no "Barcodes" tab, so the sell-out rows (keyed by '
                .'barcode) could not be mapped onto NINs. They are stored against their '
                .'barcodes and will not line up with the stock tab.';
        }

        if ($sellOut['unmapped_barcodes'] !== []) {
            $warnings[] = sprintf(
                '%d barcode(s) on the sell-out tab are not in the workbook\'s own Barcodes '
                .'map, so they carry no NIN: %s%s',
                count($sellOut['unmapped_barcodes']),
                implode(', ', array_slice($sellOut['unmapped_barcodes'], 0, 8)),
                count($sellOut['unmapped_barcodes']) > 8 ? ' …' : ''
            );
        }

        if ($sellOut['unmatched'] > 0) {
            $warnings[] = sprintf(
                '%d of %d Noon sell-out rows are for a NIN the master catalog does not hold. '
                .'They are stored and appear on the Master screen\'s fix list.',
                $sellOut['unmatched'],
                $sellOut['imported']
            );
        }

        return new ImportResult(
            rowsRead: $sellOut['read'] + $stock['rows'],
            rowsImported: $sellOut['imported'] + $stock['rows'],
            rowsSkipped: $sellOut['skipped'],
            rowsUnmatched: $sellOut['unmatched'] + $stock['unmatched'],
            warnings: $warnings,
            summary: [
                'channel' => Channel::NoonRetail->value,
                'period_start' => $sellOut['from']?->toDateString(),
                'period_end' => $sellOut['to']?->toDateString(),
                'period_days' => $sellOut['from'] && $sellOut['to']
                    ? max(1, (int) $sellOut['from']->diffInDays($sellOut['to']) + 1) : null,
                'units' => $sellOut['units'],
                'sell_out_units' => $sellOut['units'],
                'sell_out_revenue' => $sellOut['revenue'],
                'revenue_basis' => SelloutRow::BASIS_GMV,
                'sell_out_rows' => $sellOut['imported'],
                'barcode_map_entries' => count($map),
                'stock_rows' => $stock['rows'],
                'stock_units' => $stock['units'],
                // Said out loud: the SOH tab has no date of its own.
                'stock_as_at' => $stock['date'] ?? null,
                'stock_as_at_basis' => 'the workbook\'s most recent sell-out day',
                'currency' => config('currencies.default'),
            ],
        );
    }
}
