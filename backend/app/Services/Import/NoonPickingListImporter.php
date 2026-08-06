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
 * Imports a Noon picking list — what was actually delivered (§Q, M8).
 *
 * ╔══════════════════════════════════════════════════════════════════════════════════╗
 * ║  NOON ANNOTATES ONLY THE EXCEPTIONS. THIS IS THE OPPOSITE OF AMAZON AND IT IS    ║
 * ║  THE SINGLE EASIEST THING IN THIS CODEBASE TO GET CATASTROPHICALLY WRONG.        ║
 * ╚══════════════════════════════════════════════════════════════════════════════════╝
 *
 * An Amazon packing list is a positive record: every line on it shipped, and a line that
 * is absent shipped nothing. Reading a Noon picking list the same way gives an answer
 * that is not slightly wrong but catastrophically wrong — on the real PO it would report
 * 5,812 of 6,431 units delivered and an 90.4% fill rate, when the truth is 6,402 and
 * 99.55%. Six perfectly-delivered lines would be reported as never sent, and somebody
 * would go and chase them.
 *
 * The actual rule, confirmed against the real file:
 *
 *     A packing line ABSENT from the picking list was DELIVERED IN FULL.
 *     A packing line PRESENT on the picking list was delivered in the Qty it states.
 *     "OG qty", when filled in, is Noon restating what was ORDERED on a short line.
 *
 * So absence means success, not failure. On PO 287285145169960 exactly one line is short
 * — the drain cleaner, barcode 716841215014, 221 ordered against 192 delivered — and it
 * is the only row carrying an OG qty. Everything else either matches its order or is not
 * mentioned at all.
 *
 * ═══ WHERE THE ORDER COMES FROM ═══
 *
 * "Delivered in full" is only meaningful against a known order, and this importer reads
 * that order from the PACKING LIST TAB OF ITS OWN WORKBOOK rather than from the database.
 * Every Noon file carries all four tabs, so the answer never depends on whether the PO
 * was uploaded first — which removes an entire class of upload-order bug, and means a
 * picking list imported alone still produces the right delivered figures.
 *
 * ═══ WHY IT JOINS ON BARCODE ═══
 *
 * The two tabs are joined on barcode, not NIN — and they write it differently, one with a
 * leading zero and one without (see Barcode). Everything is stored against the NIN,
 * because that is what links to the PO line and to the master catalog; the barcode is
 * only ever the bridge between Noon's own two tabs.
 */
class NoonPickingListImporter implements Importer
{
    /** Why a line's delivered figure reads what it does — carried into the summary. */
    public const STATED = 'stated';
    public const IMPLIED_FULL = 'implied_full';

    public function __construct(private readonly Reconciler $reconciler) {}

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $stage = $sourceFile->upload_type->stage()
            ?? throw new RuntimeException('This upload type has no interim/final stage.');

        $workbook = Workbook::open($path);

        try {
            $metadata = NoonPoMetadata::readFrom($workbook);

            $poNumber = $metadata->poNumber
                ?? data_get($sourceFile->summary, 'po_number')
                ?? throw new RuntimeException(
                    'No PO number could be found in this file. A Noon picking list carries it on '
                    .'the tab named for the PO itself. Type the PO number on the upload form to '
                    .'import it anyway.'
                );

            $order = $this->readOrder($workbook, $poNumber);

            if ($order === []) {
                throw new RuntimeException(
                    'This file has no Packing List rows, so there is nothing to say what was '
                    .'ordered — and on Noon a line missing from the picking list means "delivered '
                    .'in full", which cannot be worked out without the order. Upload the Noon PO '
                    .'first, then this file.'
                );
            }

            $sheet = $workbook->sheet($validation->sheetName);

            return DB::transaction(fn () => $this->readRows(
                $sheet, $sourceFile, $validation, $stage, $metadata, $poNumber, $order
            ));
        } finally {
            $workbook->close();
        }
    }

    /**
     * What this PO ordered, keyed by normalised barcode.
     *
     * Read from the workbook's own Packing List tab, falling back to the PO lines already
     * in the database for the rare file that arrives without it.
     *
     * @return array<string, array{nin: string, qty: int, title: ?string, unit_cost: ?float, barcode: ?string}>
     */
    private function readOrder(Workbook $workbook, string $poNumber): array
    {
        $order = [];

        if ($workbook->hasSheet('Packing List')) {
            $sheet = $workbook->sheet('Packing List');
            $headers = HeaderMap::fromRow($sheet->row(1));

            foreach ($sheet->rows(2) as $row) {
                if (Sheet::isBlankRow($row)) {
                    continue;
                }

                $nin = $headers->text($row, 'nins', 'nin', 'zsku');
                $barcode = $headers->text($row, 'gtin', 'barcode');
                $key = Barcode::key($barcode);

                // The totals block at the foot of the tab has neither.
                if ($nin === null || $key === null) {
                    continue;
                }

                $order[$key] = [
                    'nin' => $nin,
                    'qty' => $headers->int($row, 'uom qty', 'uom quantity', 'qty') ?? 0,
                    'title' => $headers->text($row, 'product title', 'title'),
                    'unit_cost' => $headers->decimal($row, 'final cost') ?? $headers->decimal($row, 'unit rate'),
                    'barcode' => Barcode::display($barcode),
                ];
            }
        }

        if ($order !== []) {
            return $order;
        }

        // No packing tab in this workbook: fall back to the order we already hold.
        foreach (PoLine::where('marketplace', Marketplace::Noon->value)
            ->where('po_number', $poNumber)->get() as $line) {
            $key = Barcode::key($line->barcode);

            if ($key !== null) {
                $order[$key] = [
                    'nin' => $line->sku_id,
                    'qty' => (int) $line->qty_accepted,
                    'title' => $line->title,
                    'unit_cost' => $line->unit_cost === null ? null : (float) $line->unit_cost,
                    'barcode' => $line->barcode,
                ];
            }
        }

        return $order;
    }

    private function readRows(
        Sheet $sheet,
        SourceFile $sourceFile,
        ValidationResult $validation,
        Stage $stage,
        NoonPoMetadata $metadata,
        string $poNumber,
        array $order,
    ): ImportResult {
        $headers = $validation->headers;
        $delivery = $this->upsertDelivery($poNumber, $stage, $metadata, $sourceFile);

        // A file is a snapshot, not an increment: re-uploading a stage replaces its lines
        // rather than doubling them.
        ShipmentLine::where('delivery_id', $delivery->id)
            ->where('stage', $stage->value)
            ->delete();

        $read = 0;
        $skipped = 0;
        $notOnOrder = [];
        $stated = [];        // normalised barcode => delivered qty stated by the file
        $shortLines = [];

        foreach ($sheet->rows($validation->headerRow + 1) as $rowNumber => $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $key = Barcode::key($headers->text($row, 'barcodes', 'barcode', 'match key'));

            if ($key === null) {
                $skipped++;

                continue;
            }

            $qtyCell = $headers->value($row, 'qty', 'quantity');
            $hasQty = $qtyCell !== null && trim((string) $qtyCell) !== '';

            $ordered = $order[$key]['qty'] ?? null;

            /*
             * THE RULE. A stated Qty is what was delivered. A blank one means Noon had
             * nothing to say about this line, which on their files means it went in full.
             */
            $delivered = $hasQty
                ? (int) $headers->int($row, 'qty', 'quantity')
                : ($ordered ?? 0);

            if ($ordered === null) {
                // On the picking list but not on the order: a real discrepancy, kept and
                // reported rather than dropped.
                $notOnOrder[] = $headers->text($row, 'barcodes', 'barcode') ?? $key;
            }

            // "OG qty" is Noon restating the ORDER on a line they shorted. It is a
            // cross-check on our own order figure, never a replacement for it.
            $ogQty = $headers->int($row, 'og qty', 'original qty', 'og quantity');

            if ($ordered !== null && $delivered < $ordered) {
                $shortLines[] = [
                    'barcode' => $order[$key]['barcode'] ?? $key,
                    'nin' => $order[$key]['nin'] ?? null,
                    'title' => $order[$key]['title'] ?? $headers->text($row, 'short title'),
                    'ordered' => $ordered,
                    'delivered' => $delivered,
                    'short' => $ordered - $delivered,
                    'og_qty_on_file' => $ogQty,
                ];
            }

            // The same barcode twice on one picking list is Noon splitting a line; sum it.
            $stated[$key] = ($stated[$key] ?? 0) + $delivered;
        }

        // ═══ The other half of the rule: every ordered line the picking list never
        // mentions was delivered in full. This is where the six silent lines on the real
        // PO come back, and without it the fill rate is wrong by 590 units.
        $lines = [];

        foreach ($order as $key => $item) {
            $delivered = $stated[$key] ?? $item['qty'];
            $basis = array_key_exists($key, $stated) ? self::STATED : self::IMPLIED_FULL;

            if ($delivered <= 0) {
                continue;
            }

            $lines[] = [$key, $item, $delivered, $basis];
        }

        // Lines the picking list carries that the order does not: kept, and attributed to
        // their own NIN so nothing is invented about which product they are.
        foreach ($stated as $key => $delivered) {
            if (! isset($order[$key]) && $delivered > 0) {
                $lines[] = [$key, [
                    'nin' => (string) $key, 'qty' => 0, 'title' => null,
                    'unit_cost' => null, 'barcode' => (string) $key,
                ], $delivered, self::STATED];
            }
        }

        [$imported, $units, $value, $unmatched, $impliedFull] =
            $this->writeShipmentLines($lines, $delivery, $stage, $poNumber, $sourceFile);

        if ($imported === 0) {
            throw new RuntimeException('No delivered lines could be worked out from this file.');
        }

        // The delivery's own totals are the reconciler's to write: it sums BOTH stages
        // from the shipment lines, so setting one here would either duplicate its work or
        // - as it did the first time - try to write a null into the stage we did not touch.
        $this->reconciler->recomputeDelivery($delivery->fresh());
        $this->reconciler->recomputePoLinesFor(Marketplace::Noon, [$poNumber]);

        $orderedUnits = array_sum(array_column($order, 'qty'));

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            rowsUnmatched: $unmatched,
            warnings: $this->warnings($poNumber, $notOnOrder, $shortLines, $unmatched),
            summary: [
                'stage' => $stage->value,
                'po_numbers' => [$poNumber],
                'po_number' => $poNumber,
                'delivery_key' => $delivery->delivery_key,
                'ordered_lines' => count($order),
                'ordered_units' => $orderedUnits,
                'delivered_units' => $units,
                'shortfall_units' => max(0, $orderedUnits - $units),
                'fill_rate_pct' => $orderedUnits > 0 ? round($units / $orderedUnits * 100, 2) : null,
                // How the answer was reached, line by line, because the implied half is
                // the part a reader would otherwise have no way to check.
                'lines_stated_on_file' => $imported - $impliedFull,
                'lines_delivered_in_full_by_omission' => $impliedFull,
                'short_lines' => $shortLines,
                'delivered_value' => round($value, 2),
                'lines_not_on_the_order' => $notOnOrder,
            ],
        );
    }

    /** @return array{0:int,1:int,2:float,3:int,4:int} imported, units, value, unmatched, impliedFull */
    private function writeShipmentLines(
        array $lines,
        Delivery $delivery,
        Stage $stage,
        string $poNumber,
        SourceFile $sourceFile,
    ): array {
        $poLines = PoLine::where('marketplace', Marketplace::Noon->value)
            ->where('po_number', $poNumber)
            ->get()
            ->keyBy('sku_id');

        $imported = $units = $unmatched = $impliedFull = 0;
        $value = 0.0;

        foreach ($lines as [$key, $item, $delivered, $basis]) {
            $poLine = $poLines->get($item['nin']);

            if ($poLine === null) {
                $unmatched++;
            }

            if ($basis === self::IMPLIED_FULL) {
                $impliedFull++;
            }

            $unitCost = $item['unit_cost'] ?? ($poLine?->unit_cost === null ? null : (float) $poLine->unit_cost);
            $lineValue = $unitCost === null ? null : round($delivered * $unitCost, 4);

            ShipmentLine::create([
                'delivery_id' => $delivery->id,
                'marketplace' => Marketplace::Noon,
                'channel' => Channel::NoonRetail,
                'stage' => $stage,
                'po_number' => $poNumber,
                'sku_id' => $item['nin'],
                'po_line_id' => $poLine?->id,
                'product_id' => $poLine?->product_id
                    ?? ProductIdentifier::resolveProductId(Marketplace::Noon, $item['nin']),
                'is_unmatched' => $poLine === null,
                'qty' => $delivered,
                'title' => $item['title'],
                'model_number' => $item['barcode'],
                'unit_cost' => $unitCost,
                'line_value' => $lineValue,
                'currency' => $delivery->currency ?? config('currencies.default'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => now(),
                'imported_by' => $sourceFile->uploaded_by,
            ]);

            $imported++;
            $units += $delivered;
            $value += (float) ($lineValue ?? 0);
        }

        return [$imported, $units, $value, $unmatched, $impliedFull];
    }

    /**
     * One delivery per Noon PO.
     *
     * Noon has no ASN, and §Q's model is one-shot: a PO goes out once, so the interim and
     * the final are two stages of the SAME delivery. Keying on the PO number is what makes
     * re-uploading either stage land on the same row instead of breeding deliveries.
     */
    private function upsertDelivery(
        string $poNumber,
        Stage $stage,
        NoonPoMetadata $metadata,
        SourceFile $sourceFile
    ): Delivery {
        $delivery = Delivery::firstOrNew([
            'marketplace' => Marketplace::Noon->value,
            'delivery_key' => Delivery::keyFor(Marketplace::Noon, null, $poNumber),
        ]);

        $delivery->fill([
            'channel' => Channel::NoonRetail,
            'internal_ref' => $poNumber,
            'shipment_name_raw' => $metadata->sheetName,
            'fc_code' => $metadata->fulfilmentCentre() ?? $delivery->fc_code,
            'currency' => $metadata->currency ?? $delivery->currency ?? config('currencies.default'),
            // Provisional: Noon's own estimate, not a promise.
            'planned_date' => $metadata->estimatedDeliveryDate ?? $delivery->planned_date,
        ]);

        if ($stage === Stage::Interim) {
            $delivery->has_interim = true;
            $delivery->interim_uploaded_at = now();
            $delivery->interim_source_file_id = $sourceFile->id;
        } else {
            $delivery->has_final = true;
            $delivery->final_uploaded_at = now();
            $delivery->final_source_file_id = $sourceFile->id;

            /*
             * The real delivery date. Noon's files do not carry one, so the upload form
             * asks - and until somebody types it, `fulfilmentDate()` falls back to the
             * upload day and marks itself inferred rather than inventing a date (§Q).
             */
            $typed = data_get($sourceFile->summary, 'delivery_date');

            if ($typed !== null) {
                $delivery->delivered_on = Carbon::parse($typed);
                $delivery->delivery_date_is_manual = true;
            }
        }

        $delivery->save();

        return $delivery;
    }

    /** @return string[] */
    private function warnings(string $poNumber, array $notOnOrder, array $shortLines, int $unmatched): array
    {
        $warnings = [];

        if ($unmatched > 0) {
            $warnings[] = sprintf(
                '%d delivered line(s) are for PO %s, which has not been uploaded yet. They have '
                .'been stored and link up automatically when it arrives.',
                $unmatched,
                $poNumber
            );
        }

        if ($notOnOrder !== []) {
            $warnings[] = sprintf(
                '%d line(s) on the picking list are not on the order: %s. They are stored as '
                .'delivered, but a delivery of something never ordered is worth querying.',
                count($notOnOrder),
                implode(', ', array_slice($notOnOrder, 0, 5))
            );
        }

        foreach ($shortLines as $short) {
            // A short line is the whole point of a Noon picking list, so it is reported
            // as information rather than buried in a count.
            if ($short['og_qty_on_file'] !== null && $short['og_qty_on_file'] !== $short['ordered']) {
                $warnings[] = sprintf(
                    'Noon\'s "OG qty" for barcode %s says %s were ordered; our PO says %s. The PO '
                    .'is what we billed against, so ours is used - but they disagree.',
                    $short['barcode'],
                    number_format($short['og_qty_on_file']),
                    number_format($short['ordered'])
                );
            }
        }

        return $warnings;
    }
}
