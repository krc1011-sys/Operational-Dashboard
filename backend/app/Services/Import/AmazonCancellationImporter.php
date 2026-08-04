<?php

namespace App\Services\Import;

use App\Enums\CancellationResolution;
use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\Cancellation;
use App\Models\PoLine;
use App\Models\ProductIdentifier;
use App\Models\SourceFile;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\ImportResult;
use App\Services\Upload\Importer;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the Amazon cancellations sheet (§G).
 *
 * This file is the SOLE source of netting. The PO export's own "Cancelled quantity"
 * column is never used for it.
 *
 * Netting is not automatic. A cancellation is applied silently only when the units are
 * still free. If honouring it would claw back units already booked into a delivery or
 * already shipped, the row is parked as "needs your decision" and nothing is netted
 * until a human answers:
 *
 *   Deliver anyway -> units stay accepted and count as delivered, and the line carries
 *                     a chargeback-exposure warning.
 *   Pull it        -> reduce as normal.
 *
 * "Quantity Confirmed" is used only as a cross-check against the accepted quantity we
 * already hold; a mismatch warns rather than blocks.
 */
class AmazonCancellationImporter implements Importer
{
    public function __construct(private readonly Reconciler $reconciler) {}

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
        $needsDecision = 0;
        $unitsCancelled = 0;
        $unitsNetted = 0;
        $warnings = [];
        $poNumbers = [];
        $skus = [];
        $mismatches = [];

        foreach ($sheet->rows($validation->headerRow + 1) as $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $poNumber = $headers->text($row, 'po number', 'po');
            $asin = $headers->text($row, 'asin');
            $cancelled = $headers->int($row, 'quantity cancelled', 'cancelled quantity') ?? 0;

            if ($poNumber === null || $asin === null) {
                $skipped++;

                continue;
            }

            $poLine = PoLine::where('marketplace', Marketplace::Amazon->value)
                ->where('po_number', $poNumber)
                ->where('sku_id', $asin)
                ->first();

            $confirmed = $headers->int($row, 'quantity confirmed', 'confirmed quantity');

            // Decide now whether this can be netted silently (§G).
            [$resolution, $honoured, $deliveredAnyway] = $this->decide($poLine, $cancelled);

            if ($resolution === CancellationResolution::NeedsDecision) {
                $needsDecision++;
            }

            if ($poLine === null) {
                $unmatched++;
            } elseif ($confirmed !== null && $confirmed !== $poLine->qty_accepted) {
                $mismatches[] = sprintf(
                    '%s / %s: the sheet says %d confirmed, we hold %d accepted.',
                    $poNumber, $asin, $confirmed, $poLine->qty_accepted
                );
            }

            Cancellation::updateOrCreate(
                [
                    'marketplace' => Marketplace::Amazon->value,
                    'po_number' => $poNumber,
                    'sku_id' => $asin,
                ],
                [
                    'channel' => Channel::AmazonRetail,
                    'po_line_id' => $poLine?->id,
                    'product_id' => $poLine?->product_id
                        ?? ProductIdentifier::resolveProductId(Marketplace::Amazon, $asin),
                    'is_unmatched' => $poLine === null,
                    'external_id' => $headers->text($row, 'external id', 'barcode'),
                    'description' => $headers->text($row, 'description', 'title'),
                    'qty_confirmed' => $confirmed,
                    'qty_cancelled' => $cancelled,
                    'resolution' => $resolution,
                    'qty_honoured' => $honoured,
                    'qty_delivered_anyway' => $deliveredAnyway,
                    'source_file_id' => $sourceFile->id,
                    'imported_at' => now(),
                    'imported_by' => $sourceFile->uploaded_by,
                ]
            );

            $imported++;
            $unitsCancelled += $cancelled;
            $unitsNetted += $honoured;
            $poNumbers[$poNumber] = true;
            $skus[$asin] = true;
        }

        if ($imported === 0) {
            throw new RuntimeException('No cancellation rows were found - every row was missing a PO number or an ASIN.');
        }

        $this->reconciler->recomputePoLinesFor(Marketplace::Amazon, array_keys($poNumbers), array_keys($skus));

        if ($unmatched > 0) {
            $warnings[] = "{$unmatched} cancellation(s) are for POs not yet uploaded. They are "
                .'stored and will net automatically once those POs arrive.';
        }

        if ($needsDecision > 0) {
            $warnings[] = "{$needsDecision} cancellation(s) would claw back units already booked "
                .'or shipped. Nothing has been netted for those - they need a "deliver anyway" '
                .'or "pull it" decision.';
        }

        foreach (array_slice($mismatches, 0, 10) as $mismatch) {
            $warnings[] = 'Quantity Confirmed mismatch - '.$mismatch;
        }

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: $warnings,
            summary: [
                'units_cancelled' => $unitsCancelled,
                'units_netted' => $unitsNetted,
                'needs_decision' => $needsDecision,
                'po_count' => count($poNumbers),
                'confirmed_qty_mismatches' => count($mismatches),
            ],
        );
    }

    /**
     * How much of this cancellation can be netted right now.
     *
     * @return array{0: CancellationResolution, 1: int, 2: int}  resolution, honoured, delivered-anyway
     */
    private function decide(?PoLine $poLine, int $cancelled): array
    {
        if ($cancelled <= 0) {
            return [CancellationResolution::Applied, 0, 0];
        }

        // No PO line yet: store it, net nothing, revisit when the PO lands.
        if ($poLine === null) {
            return [CancellationResolution::Applied, 0, 0];
        }

        // Units already promised to a delivery or already gone.
        $committed = max($poLine->qty_booked, $poLine->qty_shipped);
        $free = max(0, $poLine->qty_accepted - $committed);

        if ($cancelled <= $free) {
            return [CancellationResolution::Applied, $cancelled, 0];
        }

        // Would eat into committed units - stop and ask (§G). Net nothing meanwhile,
        // so no figure silently changes behind the user's back.
        return [CancellationResolution::NeedsDecision, 0, 0];
    }
}
