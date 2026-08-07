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
use App\Services\Upload\Importer;
use App\Services\Upload\ImportResult;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the Amazon cancellations sheet (§G).
 *
 * This file is the SOLE source of netting. The PO export's own "Cancelled quantity"
 * column is never used for it.
 *
 * Netting is not automatic, and the rules for it are NOT in this class - they live in
 * CancellationDecider, because they also have to run later, when a late PO or a new
 * packing list changes the picture. All this importer does is read the sheet, hand each
 * row to the decider, and then let the Reconciler have the last word.
 *
 * Two things it does decide for itself:
 *
 *  - A decision somebody already made is preserved when the same row is uploaded again
 *    unchanged. If the cancelled quantity has changed, that decision was made about
 *    different numbers, so the row is reopened and asked again.
 *  - "Quantity Confirmed" is used only as a cross-check against the accepted quantity we
 *    already hold; a mismatch warns rather than blocks.
 */
class AmazonCancellationImporter implements Importer
{
    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly CancellationDecider $decider,
    ) {}

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
        $unitsCancelled = 0;
        $warnings = [];
        $poNumbers = [];
        $skus = [];
        $mismatches = [];
        $reopened = [];
        $ids = [];

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

            $existing = Cancellation::where('marketplace', Marketplace::Amazon->value)
                ->where('po_number', $poNumber)
                ->where('sku_id', $asin)
                ->first();

            // An answer somebody gave survives a re-upload of the same figures; if the
            // quantity has moved, the question has to be asked again.
            $keepDecision = $existing !== null
                && $existing->isResolvedByHuman()
                && (int) $existing->qty_cancelled === $cancelled;

            if ($existing?->isResolvedByHuman() && ! $keepDecision) {
                $reopened[] = "{$poNumber} / {$asin}";
            }

            [$resolution, $honoured, $deliveredAnyway] = $keepDecision
                ? [$existing->resolution, (int) $existing->qty_honoured, (int) $existing->qty_delivered_anyway]
                : $this->decider->decide($poLine, $cancelled);

            if ($poLine === null) {
                $unmatched++;
            } elseif ($confirmed !== null && $confirmed !== $poLine->qty_accepted) {
                $mismatches[] = sprintf(
                    '%s / %s: the sheet says %d confirmed, we hold %d accepted.',
                    $poNumber, $asin, $confirmed, $poLine->qty_accepted
                );
            }

            $cancellation = Cancellation::updateOrCreate(
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
                    // A reopened row loses its old stamp along with its old answer.
                    'resolution_note' => $keepDecision ? $existing->resolution_note : null,
                    'resolved_by' => $keepDecision ? $existing->resolved_by : null,
                    'resolved_at' => $keepDecision ? $existing->resolved_at : null,
                    'source_file_id' => $sourceFile->id,
                    'imported_at' => now(),
                    'imported_by' => $sourceFile->uploaded_by,
                ]
            );

            $imported++;
            $ids[] = $cancellation->id;
            $unitsCancelled += $cancelled;
            $poNumbers[$poNumber] = true;
            $skus[$asin] = true;
        }

        if ($imported === 0) {
            throw new RuntimeException('No cancellation rows were found - every row was missing a PO number or an ASIN.');
        }

        $this->reconciler->recomputePoLinesFor(Marketplace::Amazon, array_keys($poNumbers), array_keys($skus));

        /*
         * Report what is true AFTER reconciling, not what we guessed while reading the
         * sheet. The reconciler gets the last word - a row read early in the file can be
         * re-decided by the time the import finishes.
         */
        $stored = Cancellation::whereIn('id', $ids)->get();
        $needsDecision = $stored->filter(fn (Cancellation $c) => $c->resolution === CancellationResolution::NeedsDecision);
        $unitsNetted = (int) $stored->sum('qty_honoured');

        if ($unmatched > 0) {
            $warnings[] = "{$unmatched} cancellation(s) are for POs not yet uploaded. They are "
                .'stored and will net automatically once those POs arrive.';
        }

        if ($needsDecision->isNotEmpty()) {
            $warnings[] = $needsDecision->count().' cancellation(s) would claw back units already booked '
                .'or shipped. Nothing has been netted for those - go to Cancellations and answer '
                .'"deliver anyway" or "pull it" for each.';
        }

        if ($reopened !== []) {
            $warnings[] = count($reopened).' cancellation(s) had already been decided, but the '
                .'cancelled quantity has changed, so they are being asked again: '
                .implode(', ', array_slice($reopened, 0, 5)).'.';
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
                'needs_decision' => $needsDecision->count(),
                'decisions_reopened' => count($reopened),
                'po_count' => count($poNumbers),
                'confirmed_qty_mismatches' => count($mismatches),
            ],
        );
    }
}
