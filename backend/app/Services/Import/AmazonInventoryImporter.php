<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\InventorySnapshot;
use App\Models\SourceFile;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\Importer;
use App\Services\Upload\ImportResult;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Amazon Retail stock on hand (§P, M9).
 *
 * Same banner-then-header shape as the sell-out report, from the same export tool: row 1
 * metadata, row 2 the header, row 3 onwards one row per ASIN.
 *
 * Four figures out of this file do real work, and they answer different questions:
 *
 *   Sellable On Hand Units       what Amazon is holding. The numerator of days of cover.
 *   Aged 90+ Days Sellable Units OVERSTOCK, STATED. This is Amazon telling us stock has
 *                                sat for 90 days. It needs no run rate, no assumption
 *                                and no arithmetic, which makes it the most trustworthy
 *                                overstock signal we have - so it is surfaced directly
 *                                rather than only feeding a calculated watchlist.
 *   Open Purchase Order Quantity what is still in flight towards them. Cover that
 *                                ignores it will say "reorder" for stock already sent.
 *   Net Received Units           Amazon's own count of what they took in - an
 *                                independent check on our sell-in from the packing lists.
 *
 * `Receive Fill %` and `Overall Vendor Lead Time (days)` come along because they are
 * Amazon's own scorecard of us, on the same row, and cost nothing to keep.
 *
 * ═══ THE SNAPSHOT DATE ═══
 *
 * Stock is a LEVEL, not a flow. This file is "where things stand", so it is dated on the
 * banner's own "Report Updated" - not today, and not the window start. Re-uploading the
 * same report replaces that day's answer instead of adding a second one.
 */
class AmazonInventoryImporter implements Importer
{
    public function __construct(private readonly SkuResolver $resolver) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $workbook = Workbook::open($path);

        try {
            $sheet = $workbook->sheet($validation->sheetName);
            $banner = AmazonReportBanner::readFrom($sheet);

            return DB::transaction(fn () => $this->readRows($sheet, $sourceFile, $validation, $banner));
        } finally {
            $workbook->close();
        }
    }

    private function readRows(
        Sheet $sheet,
        SourceFile $sourceFile,
        ValidationResult $validation,
        AmazonReportBanner $banner,
    ): ImportResult {
        $headers = $validation->headers;

        /*
         * The date this stock is true as at. The banner's "Report Updated" is Amazon's
         * own answer; its window end is the next best. Today is the last resort and is
         * flagged, because dating a stale file as today is how a stock screen starts
         * lying without anybody touching it.
         */
        $asAt = $banner->reportUpdated ?? $banner->periodEnd;
        $dateInferred = $asAt === null;
        $asAt ??= now()->startOfDay();
        $date = $asAt->toDateString();

        InventorySnapshot::query()
            ->where('channel', Channel::AmazonRetail->value)
            ->whereDate('snapshot_date', $date)
            ->delete();

        $read = $skipped = $unmatched = 0;
        $soh = $aged = $openPo = $netReceived = $unsellable = 0;
        $rows = [];
        $now = now();

        foreach ($sheet->rows($validation->headerRow + 1) as $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $asin = $headers->text($row, 'asin');

            if ($asin === null) {
                $skipped++;

                continue;
            }

            [$productId] = $this->resolver->resolve(Marketplace::Amazon, $asin);

            if ($productId === null) {
                $unmatched++;
            }

            $sohUnits = $headers->int($row, 'sellable on hand units');
            $agedUnits = $headers->int($row, 'aged 90 days sellable units');
            $openPoUnits = $headers->int($row, 'open purchase order quantity');
            $netReceivedUnits = $headers->int($row, 'net received units');

            $rows[] = [
                'marketplace' => Marketplace::Amazon->value,
                'channel' => Channel::AmazonRetail->value,
                'sku_id' => $asin,
                'sku_id_type' => 'asin',
                'product_id' => $productId,
                'is_unmatched' => $productId === null,
                'title' => $headers->text($row, 'product title', 'title'),
                'brand' => $headers->text($row, 'brand'),
                'snapshot_date' => $date,
                'soh_units' => $sohUnits,
                'soh_value' => $headers->decimal($row, 'sellable on hand inventory'),
                'aged_90_units' => $agedUnits,
                'aged_90_value' => $headers->decimal($row, 'aged 90 days sellable inventory'),
                'open_po_units' => $openPoUnits,
                'net_received_units' => $netReceivedUnits,
                'net_received_value' => $headers->decimal($row, 'net received'),
                'unsellable_units' => $headers->int($row, 'unsellable on hand units'),
                'receive_fill_pct' => $this->asPercent($headers->decimal($row, 'receive fill pct')),
                'vendor_confirmation_pct' => $this->asPercent($headers->decimal($row, 'vendor confirmation pct')),
                'vendor_lead_time_days' => $headers->decimal($row, 'overall vendor lead time days', 'vendor lead time'),
                'currency' => $banner->currency ?? config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => $now,
                'imported_by' => $sourceFile->uploaded_by,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $soh += (int) $sohUnits;
            $aged += (int) $agedUnits;
            $openPo += (int) $openPoUnits;
            $netReceived += (int) $netReceivedUnits;
            $unsellable += (int) $headers->int($row, 'unsellable on hand units');
        }

        if ($rows === []) {
            throw new RuntimeException('No ASIN rows could be read from this inventory report.');
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            InventorySnapshot::insert($chunk);
        }

        return new ImportResult(
            rowsRead: $read,
            rowsImported: count($rows),
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: $this->warnings($unmatched, count($rows), $dateInferred),
            summary: [
                'channel' => Channel::AmazonRetail->value,
                'snapshot_date' => $date,
                'snapshot_date_inferred' => $dateInferred,
                'period_label' => $banner->label(),
                'units' => $soh,
                'soh_units' => $soh,
                'aged_90_units' => $aged,
                'open_po_units' => $openPo,
                'net_received_units' => $netReceived,
                'unsellable_units' => $unsellable,
                'currency' => $banner->currency ?? config('currencies.default'),
            ],
        );
    }

    /**
     * Amazon writes these as a FRACTION - 0.1636 is 16.36%, and 1 is 100%.
     * Stored as a percentage so every screen reads the same units.
     */
    private function asPercent(?float $fraction): ?float
    {
        return $fraction === null ? null : round($fraction * 100, 4);
    }

    /** @return string[] */
    private function warnings(int $unmatched, int $imported, bool $dateInferred): array
    {
        $warnings = [];

        if ($unmatched > 0) {
            $warnings[] = sprintf(
                '%d of %d ASINs holding stock are not in the master catalog, so their stock '
                .'shows no brand or category. They are on the Master screen\'s fix list.',
                $unmatched,
                $imported
            );
        }

        if ($dateInferred) {
            $warnings[] = 'This report\'s banner states no "Report Updated" date, so the stock '
                .'has been dated today. If the file is not today\'s, days of cover will be '
                .'measured against stock that has already moved.';
        }

        return $warnings;
    }
}
