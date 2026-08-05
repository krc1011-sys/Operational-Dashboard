<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\Stage;
use App\Models\Delivery;
use App\Models\PoLine;
use App\Models\ProductIdentifier;
use App\Models\ShipmentLine;
use App\Models\SourceFile;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\Importer;
use App\Services\Upload\ImportResult;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports an Amazon packing list, interim or final (§K).
 *
 * The STAGE comes from the dropdown, never from the filename - the real files include
 * some named "PACKING LIST_22183953643-AUG-25.xlsx" with no stage marker at all.
 *
 * Only the "Simple List" tab is read; "Short Titles" and "Packing List" are ignored.
 * Its cells are formulas pointing at the hidden tab, so each one is taken as its cached
 * calculated value - the file uploads exactly as the tool produced it.
 *
 * The interim and final layouts differ (the final shifts every column right to make
 * room for the invoice number, and moves the banner from D1/D2 to F1/F2), which is why
 * everything here is addressed by header name and the banner is found by its label.
 *
 * Two rules that decide whether the numbers come out right:
 *  - rows whose Title is literally "Carton total" are per-carton subtotals and are
 *    SKIPPED, or units double-count;
 *  - the same ASIN appearing on several rows (split across cartons) is kept as
 *    several rows and summed, never collapsed.
 */
class AmazonPackingListImporter implements Importer
{
    public function __construct(private readonly Reconciler $reconciler) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $stage = $sourceFile->upload_type->stage()
            ?? throw new RuntimeException('This upload type has no interim/final stage.');

        $workbook = Workbook::open($path);

        try {
            $sheet = $workbook->sheet($validation->sheetName);
            $banner = ShipmentBanner::readFrom($sheet);

            if (! $banner->isUsable()) {
                throw new RuntimeException(
                    'No shipment number could be read from this packing list. The '
                    .'"Shipment Name" banner should contain an 11-digit ASN; this file has '
                    .($banner->rawName === null ? 'no banner at all.' : '"'.$banner->rawName.'".')
                );
            }

            return DB::transaction(fn () => $this->readRows($sheet, $sourceFile, $validation, $stage, $banner));
        } finally {
            $workbook->close();
        }
    }

    private function readRows(
        Sheet $sheet,
        SourceFile $sourceFile,
        ValidationResult $validation,
        Stage $stage,
        ShipmentBanner $banner
    ): ImportResult {
        $headers = $validation->headers;

        $delivery = $this->upsertDelivery($banner, $stage, $sourceFile);

        // Re-uploading the same stage for the same delivery replaces its lines rather
        // than doubling them - the file is a snapshot, not an increment.
        ShipmentLine::where('delivery_id', $delivery->id)
            ->where('stage', $stage->value)
            ->delete();

        $read = $imported = $skipped = $unmatched = 0;
        $cartonTotals = 0;
        $units = 0;
        $poNumbers = [];
        $skus = [];
        $invoiceNumbers = [];
        $value = 0.0;

        foreach ($sheet->rows($validation->headerRow + 1) as $rowNumber => $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $title = $headers->text($row, 'title', 'short title');

            // Per-carton subtotal row - skip it or units double-count (§K).
            if (ShipmentLine::isCartonTotalRow($title)) {
                $cartonTotals++;
                $skipped++;

                continue;
            }

            $asin = $headers->text($row, 'asin');
            $poNumber = $headers->text($row, 'po', 'po number');

            if ($asin === null || $poNumber === null) {
                $skipped++;

                continue;
            }

            $qty = $headers->int($row, 'qty', 'quantity') ?? 0;
            $unitCost = $headers->decimal($row, 'unit cost', 'cost');
            $lineValue = $headers->decimal($row, 'line value');

            if ($lineValue === null && $unitCost !== null) {
                $lineValue = round($qty * $unitCost, 4);
            }

            // Match to the PO line. A miss is expected during rollout, not an error (§K).
            $poLine = PoLine::where('marketplace', Marketplace::Amazon->value)
                ->where('po_number', $poNumber)
                ->where('sku_id', $asin)
                ->first();

            if ($poLine === null) {
                $unmatched++;
            }

            ShipmentLine::create([
                'delivery_id' => $delivery->id,
                'marketplace' => Marketplace::Amazon,
                'channel' => Channel::AmazonRetail,
                'stage' => $stage,
                'po_number' => $poNumber,
                'sku_id' => $asin,
                'po_line_id' => $poLine?->id,
                'product_id' => $poLine?->product_id
                    ?? ProductIdentifier::resolveProductId(Marketplace::Amazon, $asin),
                'is_unmatched' => $poLine === null,
                'qty' => $qty,
                'carton' => $headers->text($row, 'carton'),
                'title' => $title,
                'model_number' => $headers->text($row, 'model number'),
                'unit_cost' => $unitCost,
                'line_value' => $lineValue,
                // The packing list carries no currency column; it inherits the market's
                // default rather than a symbol typed into the importer (config/currencies.php).
                'currency' => config('currencies.default'),
                'invoice_number' => $headers->text($row, 'invoice number'),
                'source_row' => $rowNumber,
                'source_file_id' => $sourceFile->id,
                'imported_at' => now(),
                'imported_by' => $sourceFile->uploaded_by,
            ]);

            $imported++;
            $units += $qty;
            $value += (float) ($lineValue ?? 0);
            $poNumbers[$poNumber] = true;
            $skus[$asin] = true;

            if ($invoice = $headers->text($row, 'invoice number')) {
                $invoiceNumbers[$invoice] = true;
            }
        }

        if ($imported === 0) {
            throw new RuntimeException('No item rows were found on the Simple List tab.');
        }

        $this->reconciler->recomputeDelivery($delivery->fresh());
        $this->reconciler->recomputePoLinesFor(Marketplace::Amazon, array_keys($poNumbers), array_keys($skus));

        $warnings = [];

        if ($unmatched > 0) {
            $warnings[] = "{$unmatched} line(s) are for POs not yet uploaded. They have been "
                .'stored and will link up automatically when those POs arrive.';
        }

        if ($delivery->has_fc_conflict) {
            $warnings[] = 'The POs in this delivery do not all ship to the same fulfilment centre. '
                .'One delivery should go to exactly one FC - worth checking.';
        }

        // The banner's own invoice total is a free cross-check on our row arithmetic.
        if ($banner->invoiceValue !== null && abs($banner->invoiceValue - $value) > 0.5) {
            $warnings[] = sprintf(
                'Our line values total %s but the sheet\'s "Invoice value" says %s.',
                number_format($value, 2),
                number_format($banner->invoiceValue, 2)
            );
        }

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: $warnings,
            summary: [
                'stage' => $stage->value,
                'asn' => $banner->asn,
                'internal_ref' => $banner->internalRef,
                'planned_date' => $banner->plannedDate?->toDateString(),
                'units' => $units,
                'line_value_total' => round($value, 2),
                'invoice_value_on_sheet' => $banner->invoiceValue,
                'carton_total_rows_skipped' => $cartonTotals,
                'po_count' => count($poNumbers),
                'po_numbers' => array_keys($poNumbers),
                'distinct_asins' => count($skus),
                'invoice_numbers' => array_keys($invoiceNumbers),
            ],
        );
    }

    private function upsertDelivery(ShipmentBanner $banner, Stage $stage, SourceFile $sourceFile): Delivery
    {
        $delivery = Delivery::firstOrNew([
            'marketplace' => Marketplace::Amazon->value,
            'delivery_key' => Delivery::keyFor(Marketplace::Amazon, $banner->asn, $sourceFile->original_filename),
        ]);

        $delivery->fill([
            'channel' => Channel::AmazonRetail,
            'asn' => $banner->asn,
            'internal_ref' => $banner->internalRef ?? $delivery->internal_ref,
            'shipment_name_raw' => $banner->rawName,
            // Provisional only: Amazon reschedules per FC capacity, so nothing hangs
            // off this date (§K).
            'planned_date' => $banner->plannedDate ?? $delivery->planned_date,
        ]);

        if ($stage === Stage::Interim) {
            $delivery->has_interim = true;
            $delivery->interim_uploaded_at = now();
            $delivery->interim_source_file_id = $sourceFile->id;
            // Not every sheet carries an "Invoice value" banner; fall back to 0 and let
            // the reconciler total the line values instead.
            $delivery->value_interim = $banner->invoiceValue ?? $delivery->value_interim ?? 0;
        } else {
            $delivery->has_final = true;
            $delivery->final_uploaded_at = now();
            $delivery->final_source_file_id = $sourceFile->id;
            $delivery->value_final = $banner->invoiceValue ?? $delivery->value_final ?? 0;
            // A final packing list existing for an ASN means those units shipped (§K).
            $delivery->delivered_on ??= $banner->plannedDate;
        }

        $delivery->save();

        return $delivery;
    }
}
