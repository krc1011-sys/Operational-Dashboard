<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\PoLine;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
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
 * Imports a Noon purchase order (§Q, M8).
 *
 * ═══ THE PACKING LIST IS THE ORDER ═══
 *
 * Noon's naming is the opposite way round from Amazon's and it is the first thing to get
 * wrong. On Noon, "Packing List" is WHAT WAS ORDERED and "Picking List" is WHAT WAS
 * DELIVERED. This importer reads the Packing List; NoonPickingListImporter reads the
 * other one.
 *
 * The PO number, ship-to, currency and dates come from a fourth tab whose NAME is the PO
 * number — see NoonPoMetadata. The filename is not trusted (§T).
 *
 * ═══ WHAT DIFFERS FROM AMAZON, AND WHY EACH MATTERS ═══
 *
 *  - NO ACCEPT STEP. Noon orders what it orders; there is no requested-vs-accepted
 *    negotiation and therefore NO CONFIRMATION RATE. `qty_requested` and `qty_accepted`
 *    are both set to the ordered quantity so every downstream figure that divides by
 *    accepted keeps working, and the confirmation-rate screens correctly read 100%
 *    rather than dividing by zero.
 *  - THE JOIN KEY IS THE NIN, not an ASIN. `Z2BDF218C04567F51F081Z-1` is Noon's own
 *    product id, and it is what the master sheet's "Customer Product Code (Noon)" column
 *    already holds — so the catalog link works the moment the master is loaded.
 *  - THE BARCODE IS THE INTERNAL JOIN. Noon's two tabs are joined to each other on
 *    barcode, not on NIN, and the two tabs write it differently (see Barcode). It is
 *    stored here so the picking list can find its line.
 *  - LINE VALUE IS VAT-INCLUSIVE. "Total Amount" is what the PO is worth and is what the
 *    sheet's own Grand Total adds up; "Unit Rate" is ex-VAT and "Final Cost" is the
 *    VAT-inclusive unit price. Unit cost is stored as Final Cost so that
 *    units x unit_cost reconciles with the line value, which is the invariant every
 *    order-value figure in the app relies on.
 */
class NoonPoImporter implements Importer
{
    public function __construct(private readonly Reconciler $reconciler) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $workbook = Workbook::open($path);

        try {
            $metadata = NoonPoMetadata::readFrom($workbook);

            $poNumber = $metadata->poNumber
                ?? data_get($sourceFile->summary, 'po_number')
                ?? null;

            if ($poNumber === null) {
                throw new RuntimeException(
                    'No PO number could be found in this file. A Noon PO carries it on the tab '
                    .'named for the PO itself; this workbook has: '
                    .implode(', ', $workbook->sheetNames()).'. Type the PO number on the upload '
                    .'form to import it anyway.'
                );
            }

            $sheet = $workbook->sheet($validation->sheetName);

            // The picking tab travels in the same workbook. Whether it holds anything is
            // reported, because a base "V1" file does carry one and ignoring it silently
            // would look like the delivery had not happened.
            $pickingRows = $this->countPickingRows($workbook);

            return DB::transaction(fn () => $this->readRows(
                $sheet, $sourceFile, $validation, $metadata, $poNumber, $pickingRows
            ));
        } finally {
            $workbook->close();
        }
    }

    private function readRows(
        Sheet $sheet,
        SourceFile $sourceFile,
        ValidationResult $validation,
        NoonPoMetadata $metadata,
        string $poNumber,
        int $pickingRows,
    ): ImportResult {
        $headers = $validation->headers;

        $purchaseOrder = $this->upsertPurchaseOrder($poNumber, $metadata, $sourceFile);

        $read = $imported = $skipped = 0;
        $units = 0;
        $value = 0.0;
        $unlinked = 0;
        $skus = [];
        $duplicates = [];

        foreach ($sheet->rows($validation->headerRow + 1) as $rowNumber => $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $nin = $headers->text($row, 'nins', 'nin', 'zsku');

            // The tab ends in a Sub Total / CST / Grand Total block. Those rows have no
            // NIN, which is what tells them apart from data - never a row number.
            if ($nin === null) {
                $skipped++;

                continue;
            }

            $qty = $headers->int($row, 'uom qty', 'uom quantity', 'qty') ?? 0;
            $barcode = $headers->text($row, 'gtin', 'barcode');

            // Final Cost is the VAT-inclusive unit price; Unit Rate is ex-VAT. Total
            // Amount is the line's own value and wins over anything we could multiply,
            // because it is the figure Noon's Grand Total is built from.
            $unitRate = $headers->decimal($row, 'unit rate', 'unit cost');
            $vat = $headers->decimal($row, 'vat');
            $finalCost = $headers->decimal($row, 'final cost');
            $lineValue = $headers->decimal($row, 'total amount', 'line value');

            /*
             * THE UNIT COST IS DERIVED FROM THE LINE VALUE, not from "Final Cost".
             *
             * Every order-value figure in the app is units x unit_cost, so the unit cost
             * has to be the one that reproduces what Noon actually invoiced. "Final Cost"
             * is the VAT-inclusive rate ROUNDED TO THE FILS for printing - 2.89 where the
             * true rate is 2.8875 - and multiplying the rounded figure back up puts the
             * PO 6.57 out on the real file. Total Amount is the line's authoritative
             * value and is what Noon's own Grand Total is built from, so the rate comes
             * from dividing it back down.
             *
             * Final Cost and Unit Rate remain the fallbacks for a line with no total.
             */
            $unitCost = ($lineValue !== null && $qty > 0)
                ? $lineValue / $qty
                : ($finalCost ?? $unitRate);

            if ($lineValue === null && $unitCost !== null) {
                $lineValue = round($qty * $unitCost, 4);
            }

            $productId = ProductIdentifier::resolveProductId(Marketplace::Noon, $nin);

            if ($productId === null) {
                $unlinked++;
            }

            if (isset($skus[$nin])) {
                $duplicates[$nin] = true;
            }

            $skus[$nin] = true;

            PoLine::updateOrCreate(
                [
                    'marketplace' => Marketplace::Noon->value,
                    'po_number' => $poNumber,
                    'sku_id' => $nin,
                ],
                [
                    'purchase_order_id' => $purchaseOrder->id,
                    'channel' => Channel::NoonRetail,
                    'sku_id_type' => 'nin',
                    'product_id' => $productId,
                    'title' => $headers->text($row, 'product title', 'title', 'description'),
                    // Stored in the display form Noon printed - the leading zero is real
                    // to them - while every JOIN goes through Barcode::key().
                    'barcode' => Barcode::display($barcode),
                    'merchant_sku' => $headers->text($row, 'seller sku'),
                    'model_number' => $headers->text($row, 'model number'),
                    /*
                     * Noon has NO ACCEPT STEP: it orders what it orders. Requested and
                     * accepted are both the ordered quantity so that fill rate, net
                     * accepted and shortfall - all of which divide by accepted - keep
                     * working unchanged, and confirmation rate reads 100% rather than
                     * dividing by zero (§Q).
                     */
                    'qty_requested' => $qty,
                    'qty_accepted' => $qty,
                    'qty_cancelled_po_file' => 0,
                    'unit_cost' => $unitCost,
                    'currency' => $metadata->currency ?? config('currencies.default'),
                    'ship_to_fc' => $metadata->fulfilmentCentre(),
                    'expected_date' => $metadata->estimatedDeliveryDate,
                    'source_file_id' => $sourceFile->id,
                    'imported_at' => now(),
                    'imported_by' => $sourceFile->uploaded_by,
                ]
            );

            $imported++;
            $units += $qty;
            $value += (float) ($lineValue ?? 0);
        }

        if ($imported === 0) {
            throw new RuntimeException(
                'No item rows were found on the Packing List tab. On Noon the Packing List is '
                .'the ORDER - if this file is a delivery, choose one of the Picking List types.'
            );
        }

        // Picking lines uploaded before their PO now have something to attach to (§K),
        // and the whole PO is recomputed from whatever fulfilment already exists.
        $this->reconciler->attachOrphanShipmentLines(Marketplace::Noon, [$poNumber]);
        $this->reconciler->recomputePoLinesFor(Marketplace::Noon, [$poNumber]);

        $warnings = $this->warnings($metadata, $units, $value, $unlinked, $imported, $duplicates, $pickingRows);

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            rowsUnmatched: $unlinked,
            warnings: $warnings,
            summary: [
                'po_numbers' => [$poNumber],
                'po_number' => $poNumber,
                'lines' => $imported,
                'units' => $units,
                'order_value' => round($value, 2),
                'currency' => $metadata->currency ?? config('currencies.default'),
                'ship_to' => $metadata->fulfilmentCentre(),
                'order_date' => $metadata->orderDate?->toDateString(),
                'estimated_delivery_date' => $metadata->estimatedDeliveryDate?->toDateString(),
                'sheet_sub_total_units' => $metadata->subTotalUnits,
                'sheet_grand_total' => $metadata->grandTotal,
                'linked_to_catalog' => $imported - $unlinked,
                'picking_rows_in_this_file' => $pickingRows,
            ],
        );
    }

    private function upsertPurchaseOrder(
        string $poNumber,
        NoonPoMetadata $metadata,
        SourceFile $sourceFile
    ): PurchaseOrder {
        $order = PurchaseOrder::firstOrNew([
            'marketplace' => Marketplace::Noon->value,
            'po_number' => $poNumber,
        ]);

        $order->fill([
            'channel' => Channel::NoonRetail,
            'order_date' => $metadata->orderDate ?? $order->order_date
                ?? data_get($sourceFile->summary, 'order_date'),
            'ship_to_fc' => $metadata->fulfilmentCentre() ?? $order->ship_to_fc,
            'vendor_code' => $metadata->partnerCode ?? $order->vendor_code,
            'currency' => $metadata->currency ?? $order->currency ?? config('currencies.default'),
            'source_file_id' => $sourceFile->id,
            'imported_at' => now(),
        ]);

        $order->save();

        return $order;
    }

    /** How many data rows the picking tab of THIS workbook holds, if any. */
    private function countPickingRows(Workbook $workbook): int
    {
        if (! $workbook->hasSheet('Picking List')) {
            return 0;
        }

        $sheet = $workbook->sheet('Picking List');
        $rows = 0;

        foreach ($sheet->rows(2) as $row) {
            if (! Sheet::isBlankRow($row)) {
                $rows++;
            }
        }

        return $rows;
    }

    /** @return string[] */
    private function warnings(
        NoonPoMetadata $metadata,
        int $units,
        float $value,
        int $unlinked,
        int $imported,
        array $duplicates,
        int $pickingRows,
    ): array {
        $warnings = [];

        // The sheet totals its own order. Disagreeing with it means one of us has
        // miscounted, and it is worth saying so rather than quietly preferring ours.
        if ($metadata->subTotalUnits !== null && $metadata->subTotalUnits !== $units) {
            $warnings[] = sprintf(
                'Our rows total %s units but the sheet\'s own Sub Total says %s. Worth checking '
                .'before this PO is used for anything.',
                number_format($units),
                number_format($metadata->subTotalUnits)
            );
        }

        if ($unlinked > 0) {
            $warnings[] = sprintf(
                '%d of %d line(s) have a NIN that is not in the master catalog, so they carry no '
                .'brand, category or margin. Order value is unaffected. Add them to the master, '
                .'or upload the master sheet again, and they link up on the next recompute.',
                $unlinked,
                $imported
            );
        }

        if ($duplicates !== []) {
            $warnings[] = sprintf(
                'The same NIN appears on more than one row (%s). Only the last was kept - a Noon '
                .'PO should carry one row per product.',
                implode(', ', array_slice(array_keys($duplicates), 0, 5))
            );
        }

        if ($pickingRows > 0) {
            $warnings[] = sprintf(
                'This workbook also carries a Picking List with %d row(s) — what was actually '
                .'delivered. It has NOT been imported: this upload is the order. Upload the same '
                .'file again as a Noon Final Picking List to book the delivery.',
                $pickingRows
            );
        }

        return $warnings;
    }
}
