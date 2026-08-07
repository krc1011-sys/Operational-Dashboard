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
use App\Support\Barcode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Amazon DFS stock — a basic current snapshot, and DELIBERATELY NO MORE (§R, M9).
 *
 * ╔══════════════════════════════════════════════════════════════════════════════════╗
 * ║  DFS STOCK IS PROVISIONAL. Every row is stored flagged, and every screen that    ║
 * ║  shows it says "provisional — pending internal-tool link".                       ║
 * ╚══════════════════════════════════════════════════════════════════════════════════╝
 *
 * The reason is not that the file is bad, it is that the file is not ours. This is
 * Amazon's bulk view of what they believe our direct-fulfilment stock to be. The real
 * DFS position lives in our own warehouse system, and it is the OperON ↔ in-house-tool
 * integration that will bring it in. Until then this is a starting figure, good enough
 * to put a days-of-cover number on a screen so the shape of the channel is visible, and
 * not good enough to reorder against without checking.
 *
 * So this importer is deliberately thin. It stores the identifier, the quantity, the
 * warehouse and the status, and it stops. No deeper DFS stock logic is built here —
 * that would be building on a number we have already said not to trust, and it would
 * have to be unpicked when the real feed arrives.
 *
 * ═══ THE FILE ═══
 *
 * The only CSV in the system, and its identifier columns arrive as Excel TEXT FORMULAS:
 *
 *     SKU   ="0726208185355"      (GTIN-14, leading zero preserved)
 *     UPC   ="726208185355"       (the same barcode, GTIN-13)
 *
 * That wrapper is the exporter defending the leading zero against Excel, and it is why
 * neither is used as a key: the ASIN is. Barcode::key reduces both to the same digits
 * for search and display.
 */
class DfsInventoryImporter implements Importer
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

        /*
         * The file carries no date at all - it is "now" from Amazon's side. Its own
         * filename holds the export timestamp, but §T is explicit about not trusting
         * filenames, so it is dated on the day it was uploaded and the provisional note
         * covers what that means.
         */
        $date = now()->startOfDay()->toDateString();

        InventorySnapshot::query()
            ->where('channel', Channel::AmazonDfs->value)
            ->whereDate('snapshot_date', $date)
            ->delete();

        $read = $skipped = $unmatched = 0;
        $units = 0;
        $warehouses = [];
        $rows = [];
        $seen = [];
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

            $available = $headers->int($row, 'available units');

            /*
             * One warehouse per row today, but the column exists, so the same ASIN could
             * arrive twice. Stock is a level per SKU per day, so the quantities are
             * summed rather than one silently replacing the other.
             */
            if (isset($seen[$asin])) {
                $index = $seen[$asin];
                $rows[$index]['soh_units'] = (int) $rows[$index]['soh_units'] + (int) $available;
                $units += (int) $available;

                continue;
            }

            [$productId] = $this->resolver->resolve(Marketplace::Amazon, $asin);

            if ($productId === null) {
                $unmatched++;
            }

            // Either column is the same barcode, one a GTIN-14 and one a GTIN-13.
            $barcode = $headers->text($row, 'upc') ?? $headers->text($row, 'sku');
            $warehouse = $headers->text($row, 'warehouse');

            if ($warehouse !== null) {
                $warehouses[$warehouse] = true;
            }

            $seen[$asin] = count($rows);

            $rows[] = [
                'marketplace' => Marketplace::Amazon->value,
                'channel' => Channel::AmazonDfs->value,
                'sku_id' => $asin,
                'sku_id_type' => 'asin',
                'barcode' => Barcode::key($barcode),
                'barcode_key' => Barcode::key($barcode),
                'product_id' => $productId,
                'is_unmatched' => $productId === null,
                'title' => $headers->text($row, 'title', 'description'),
                'snapshot_date' => $date,
                'soh_units' => $available,
                'warehouse_code' => $warehouse,
                'warehouse_name' => $headers->text($row, 'warehouse name'),
                'status' => $headers->text($row, 'status'),
                // The whole point of this importer.
                'is_provisional' => true,
                'provisional_note' => InventorySnapshot::DFS_PROVISIONAL_NOTE,
                'currency' => config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => $now,
                'imported_by' => $sourceFile->uploaded_by,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $units += (int) $available;
        }

        if ($rows === []) {
            throw new RuntimeException('No stock rows could be read from this DFS inventory file.');
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            InventorySnapshot::insert($chunk);
        }

        return new ImportResult(
            rowsRead: $read,
            rowsImported: count($rows),
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: array_filter([
                'DFS stock is '.InventorySnapshot::DFS_PROVISIONAL_NOTE.'. It is Amazon\'s view '
                    .'of our direct-fulfilment stock, not our own warehouse system, so DFS days '
                    .'of cover is shown as an indication and labelled everywhere it appears.',
                $unmatched === 0 ? null : sprintf(
                    '%d of %d DFS stock rows name an ASIN the master catalog does not hold. '
                    .'They are on the Master screen\'s fix list.',
                    $unmatched,
                    count($rows)
                ),
            ]),
            summary: [
                'channel' => Channel::AmazonDfs->value,
                'snapshot_date' => $date,
                'units' => $units,
                'soh_units' => $units,
                'warehouses' => array_keys($warehouses),
                'is_provisional' => true,
                'provisional_note' => InventorySnapshot::DFS_PROVISIONAL_NOTE,
                'currency' => config('currencies.default'),
            ],
        );
    }
}
