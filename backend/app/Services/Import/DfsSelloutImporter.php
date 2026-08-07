<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\DfsOrder;
use App\Models\SelloutRow;
use App\Models\SourceFile;
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
 * Amazon Direct Fulfilment — orders, which on DFS ARE the sell-out (§R, M9).
 *
 * DFS has no PO and no fill rate: Amazon routes real end-customer orders to us and we
 * ship them from our own stock. So there is no sell-in step to compare against on this
 * channel, and the order line is simultaneously the sale and the sell-out. That makes
 * DFS the one channel where sell-through is not a meaningful ratio, and the screens say
 * so rather than printing 100%.
 *
 * ═══ TWO TABLES, ONE PASS, AND WHY ═══
 *
 * 1. `dfs_orders` keeps every line as it arrived. That is the detail §R's "upcoming
 *    committed deliveries" lookup needs, and it is the record we would reconcile an
 *    invoice against.
 *
 * 2. `sellout_rows` gets a PER-ASIN-PER-DAY projection of the same lines. It is a
 *    projection, not a second source: it is written from the rows just stored, in the
 *    same transaction, and rebuilt whole whenever the file is re-uploaded. The reason it
 *    exists is that velocity, days of cover and the watchlists must work identically on
 *    all three channels, and they would not if DFS alone had to be special-cased into
 *    every query.
 *
 * ═══ THE FILE ═══
 *
 * Transaction level, header on row 1. The invoice date is an EXCEL SERIAL (46204 =
 * 1 Jul 2026) and the "SKU" column is a barcode, not a seller SKU — it is stored
 * normalised so it can be searched, and the ASIN remains the only join key (§B).
 *
 * Monthly extracts overlap, so a line is keyed on (order id, ASIN) and re-uploading the
 * same period updates rather than duplicates.
 */
class DfsSelloutImporter implements Importer
{
    public function __construct(private readonly SkuResolver $resolver) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $workbook = Workbook::open($path);

        try {
            $sheet = $workbook->sheet($validation->sheetName);

            return DB::transaction(fn () => $this->readRows($sheet, $sourceFile, $validation));
        } finally {
            $workbook->close();
        }
    }

    private function readRows(Sheet $sheet, SourceFile $sourceFile, ValidationResult $validation): ImportResult
    {
        $headers = $validation->headers;

        $read = $imported = $skipped = $unmatched = 0;
        $qty = 0;
        $amount = 0.0;
        $undated = 0;
        $now = now();

        /** @var array<string, array{units:int, revenue:float, title:?string}> "asin|date" */
        $daily = [];
        $minDate = null;
        $maxDate = null;

        foreach ($sheet->rows($validation->headerRow + 1) as $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $asin = $headers->text($row, 'asin');
            $orderId = $headers->text($row, 'order id', 'order');

            // Both halves of the key, or the line cannot be stored without inventing one.
            if ($asin === null || $orderId === null) {
                $skipped++;

                continue;
            }

            $barcode = $headers->text($row, 'sku', 'seller sku');
            [$productId] = $this->resolver->resolve(Marketplace::Amazon, $asin);

            if ($productId === null) {
                $unmatched++;
            }

            $date = $headers->date($row, 'invoice date');
            $lineQty = (int) ($headers->int($row, 'qty', 'quantity') ?? 0);
            $lineAmount = $headers->decimal($row, 'invoice amount', 'amount');

            DfsOrder::updateOrCreate(
                ['order_id' => $orderId, 'sku_id' => $asin],
                [
                    'marketplace' => Marketplace::Amazon,
                    'channel' => Channel::AmazonDfs,
                    'invoice_number' => $headers->text($row, 'invoice number provided to amazon', 'invoice number', 'invoice'),
                    'invoice_date' => $date,
                    'seller_sku' => Barcode::display($barcode),
                    'description' => $headers->text($row, 'item description', 'description'),
                    'product_id' => $productId,
                    'is_unmatched' => $productId === null,
                    'qty' => $lineQty,
                    'invoice_amount' => $lineAmount,
                    'currency' => config('currencies.default'),
                    'source_file_id' => $sourceFile->id,
                    'imported_at' => $now,
                    'imported_by' => $sourceFile->uploaded_by,
                ]
            );

            $imported++;
            $qty += $lineQty;
            $amount += (float) ($lineAmount ?? 0);

            if ($date === null) {
                // Kept as an order, but it cannot join a daily series without a day.
                $undated++;

                continue;
            }

            $minDate = $minDate === null ? $date->copy() : $minDate->min($date);
            $maxDate = $maxDate === null ? $date->copy() : $maxDate->max($date);

            $key = $asin.'|'.$date->toDateString();
            $daily[$key] ??= [
                'asin' => $asin,
                'date' => $date->toDateString(),
                'units' => 0,
                'revenue' => 0.0,
                'title' => $headers->text($row, 'item description', 'description'),
                'barcode' => $barcode,
                'product_id' => $productId,
            ];
            $daily[$key]['units'] += $lineQty;
            $daily[$key]['revenue'] += (float) ($lineAmount ?? 0);
        }

        if ($imported === 0) {
            throw new RuntimeException('No DFS order lines could be read from this file.');
        }

        $projected = $this->projectDaily($daily, $sourceFile, $minDate, $maxDate, $now);

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: $this->warnings($unmatched, $imported, $undated),
            summary: [
                'channel' => Channel::AmazonDfs->value,
                'period_start' => $minDate?->toDateString(),
                'period_end' => $maxDate?->toDateString(),
                'period_days' => $minDate && $maxDate
                    ? max(1, (int) $minDate->diffInDays($maxDate) + 1) : null,
                'orders' => $imported,
                'units' => $qty,
                'sell_out_units' => $qty,
                'sell_out_revenue' => round($amount, 2),
                'revenue_basis' => SelloutRow::BASIS_INVOICE_AMOUNT,
                'daily_sellout_rows' => $projected,
                'undated_lines' => $undated,
                'currency' => config('currencies.default'),
            ],
        );
    }

    /**
     * Write the per-ASIN-per-day sell-out projection.
     *
     * Rebuilt whole for the window this file covers, so a re-upload cannot leave a stale
     * day behind and cannot double-count one.
     *
     * @param  array<string, array<string, mixed>>  $daily
     */
    private function projectDaily(array $daily, SourceFile $sourceFile, ?Carbon $from, ?Carbon $to, $now): int
    {
        if ($daily === [] || $from === null || $to === null) {
            return 0;
        }

        SelloutRow::query()
            ->where('channel', Channel::AmazonDfs->value)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_end', '<=', $to)
            ->delete();

        foreach (array_chunk($daily, 500) as $chunk) {
            SelloutRow::insert(array_map(fn (array $d) => [
                'marketplace' => Marketplace::Amazon->value,
                'channel' => Channel::AmazonDfs->value,
                'grain' => SelloutRow::GRAIN_DAY,
                'sku_id' => $d['asin'],
                'sku_id_type' => 'asin',
                'barcode' => Barcode::display($d['barcode']),
                'barcode_key' => Barcode::key($d['barcode']),
                'product_id' => $d['product_id'],
                'is_unmatched' => $d['product_id'] === null,
                'title' => $d['title'],
                'period_start' => $d['date'],
                'period_end' => $d['date'],
                'shipped_units' => $d['units'],
                'revenue' => round($d['revenue'], 4),
                'revenue_basis' => SelloutRow::BASIS_INVOICE_AMOUNT,
                'currency' => config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => $now,
                'imported_by' => $sourceFile->uploaded_by,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return count($daily);
    }

    /** @return string[] */
    private function warnings(int $unmatched, int $imported, int $undated): array
    {
        $warnings = [];

        if ($unmatched > 0) {
            $warnings[] = sprintf(
                '%d of %d DFS order lines name an ASIN the master catalog does not hold. They '
                .'are stored and appear on the Master screen\'s fix list.',
                $unmatched,
                $imported
            );
        }

        if ($undated > 0) {
            $warnings[] = sprintf(
                '%d line(s) carry no invoice date. They are stored as orders but are left out '
                .'of the daily sell-out series, because a run rate needs a day.',
                $undated
            );
        }

        return $warnings;
    }
}
