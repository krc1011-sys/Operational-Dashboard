<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\SelloutRow;
use App\Models\SourceFile;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\Importer;
use App\Services\Upload\ImportResult;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Amazon Retail sell-out — what Amazon sold on to end customers (§P, M9).
 *
 * ╔══════════════════════════════════════════════════════════════════════════════════╗
 * ║  "SHIPPED REVENUE" IS NOT OUR REVENUE. "SHIPPED COGS" IS.                        ║
 * ╚══════════════════════════════════════════════════════════════════════════════════╝
 *
 * This is an Amazon VENDOR report, written from Amazon's side of the desk, so its column
 * names describe Amazon's books rather than ours:
 *
 *     Shipped Revenue   what the END CUSTOMER paid Amazon    → not ours, ever
 *     Shipped COGS      Amazon's cost of goods = WHAT AMAZON PAID US  → our revenue
 *
 * On the real file those read AED 1,691,050.50 and AED 1,704,390.15. They are within 1%
 * of each other, which is the dangerous part: taking the wrong one would not look wrong
 * on any screen, it would just quietly understate what the channel is worth to us and
 * every sell-through ratio built on it. `revenue` holds Shipped COGS and records that it
 * did; Shipped Revenue is kept for context and is never summed as ours.
 *
 * ═══ SHAPE ═══
 *
 * Row 1 is a metadata banner, row 2 the header, row 3 onwards the data — one row per
 * ASIN, AGGREGATED over the window the banner states. There is no daily detail, so the
 * only run rate this file can support is a PERIOD AVERAGE (units ÷ window days), and it
 * is labelled as one wherever it is shown. See AmazonReportBanner.
 *
 * A row with returns and no sales is real and is kept: blanks stay blank rather than
 * becoming zeros, because "sold none" and "not reported" are different facts.
 */
class AmazonSelloutImporter implements Importer
{
    public function __construct(private readonly SkuResolver $resolver) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $workbook = Workbook::open($path);

        try {
            $sheet = $workbook->sheet($validation->sheetName);
            $banner = AmazonReportBanner::readFrom($sheet);

            if (! $banner->hasPeriod()) {
                throw new RuntimeException(
                    'This report does not state its reporting window. Row 1 of an Amazon '
                    .'sell-out export carries "Viewing Range=[dd/mm/yyyy - dd/mm/yyyy]", and '
                    .'without it the units cannot be turned into a daily rate. Re-download '
                    .'the report rather than editing the window in by hand.'
                );
            }

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
         * A re-upload of the same window REPLACES it. The unique key would catch a
         * duplicate row, but a SKU that has dropped out of the report between exports
         * would otherwise linger with its old figures and keep counting.
         */
        SelloutRow::query()
            ->where('channel', Channel::AmazonRetail->value)
            ->whereDate('period_start', $banner->periodStart)
            ->whereDate('period_end', $banner->periodEnd)
            ->delete();

        $read = $imported = $skipped = $unmatched = 0;
        $units = $returns = 0;
        $revenue = $consumerRevenue = 0.0;
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

            // OUR revenue. Named once, here, and never re-derived downstream.
            $shippedCogs = $headers->decimal($row, 'shipped cogs');
            $shippedUnits = $headers->int($row, 'shipped units');

            SelloutRow::create([
                'marketplace' => Marketplace::Amazon,
                'channel' => Channel::AmazonRetail,
                'grain' => SelloutRow::GRAIN_PERIOD,
                'sku_id' => $asin,
                'sku_id_type' => 'asin',
                'product_id' => $productId,
                'is_unmatched' => $productId === null,
                'title' => $headers->text($row, 'product title', 'title'),
                'brand' => $headers->text($row, 'brand'),
                'period_start' => $banner->periodStart,
                'period_end' => $banner->periodEnd,
                'report_updated_at' => $banner->reportUpdated,
                // Amazon's consumer-side figure. Context only - see the class comment.
                'shipped_revenue' => $headers->decimal($row, 'shipped revenue'),
                'shipped_cogs' => $shippedCogs,
                'revenue' => $shippedCogs,
                'revenue_basis' => SelloutRow::BASIS_SHIPPED_COGS,
                'shipped_units' => $shippedUnits,
                'customer_returns' => $headers->int($row, 'customer returns', 'returns'),
                'currency' => $banner->currency ?? config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => $now,
                'imported_by' => $sourceFile->uploaded_by,
            ]);

            $imported++;
            $units += (int) $shippedUnits;
            $returns += (int) $headers->int($row, 'customer returns', 'returns');
            $revenue += (float) ($shippedCogs ?? 0);
            $consumerRevenue += (float) ($headers->decimal($row, 'shipped revenue') ?? 0);
        }

        if ($imported === 0) {
            throw new RuntimeException('No ASIN rows could be read from this sell-out report.');
        }

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: $this->warnings($unmatched, $imported),
            summary: [
                'channel' => Channel::AmazonRetail->value,
                'period_start' => $banner->periodStart->toDateString(),
                'period_end' => $banner->periodEnd->toDateString(),
                'period_days' => $banner->days(),
                'period_label' => $banner->label(),
                'report_updated' => $banner->reportUpdated?->toDateString(),
                'units' => $units,
                'sell_out_units' => $units,
                'sell_out_revenue' => round($revenue, 2),
                'revenue_basis' => SelloutRow::BASIS_SHIPPED_COGS,
                // Printed beside ours so the difference is visible rather than a footnote.
                'consumer_retail_revenue' => round($consumerRevenue, 2),
                'customer_returns' => $returns,
                'currency' => $banner->currency ?? config('currencies.default'),
            ],
        );
    }

    /** @return string[] */
    private function warnings(int $unmatched, int $imported): array
    {
        if ($unmatched === 0) {
            return [];
        }

        return [sprintf(
            '%d of %d ASINs on this report are not in the master catalog, so they carry no '
            .'brand, category or margin. They are stored and link up on their own when the '
            .'master sheet catches up; they are listed on the Master screen\'s fix list.',
            $unmatched,
            $imported
        )];
    }
}
