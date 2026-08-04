<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\UploadType;
use App\Models\PoLine;
use App\Models\ProductIdentifier;
use App\Models\PurchaseOrder;
use App\Models\SourceFile;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\ImportResult;
use App\Services\Upload\Importer;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports an Amazon purchase order (§C). Handles both real-world formats:
 *
 *  - the BULK "PO Item Export" (.xls, "Line Items"): many POs and FCs, one row per
 *    PO x ASIN. The twice-weekly operational upload.
 *  - the SINGLE "PurchaseOrder.xlsx": one PO, 87 rows, and NO PO or Ship-to column.
 *    The PO number therefore comes from the filename, or from the box on the upload
 *    form when the filename does not carry one (the real sample is literally named
 *    "PurchaseOrder.xlsx").
 *
 * Both are read through the same header-name mapping, so the differing column names
 * ("Requested quantity" vs "Quantity Requested") need no special casing.
 *
 * Re-uploading upserts on (marketplace, po_number, sku_id): the latest snapshot wins
 * and POs accumulate over time, exactly as §C requires.
 */
class AmazonPoImporter implements Importer
{
    public function __construct(private readonly Reconciler $reconciler) {}

    /**
     * PO headers already handled during THIS import, so a 126-row export does not
     * re-save the same 10 POs on every line.
     *
     * Deliberately an instance property reset per import, never a static: a static
     * would survive into the next import in the same process and hand back a
     * PurchaseOrder belonging to a previous run.
     *
     * @var array<string, PurchaseOrder>
     */
    private array $poCache = [];

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $this->poCache = [];

        $isSingle = $sourceFile->upload_type === UploadType::AmazonPoSingle;

        $fallbackPo = $isSingle
            ? $this->resolveSinglePoNumber($sourceFile)
            : null;

        $workbook = Workbook::open($path);

        try {
            $sheet = $workbook->sheet($validation->sheetName);

            return DB::transaction(fn () => $this->readRows(
                $sheet, $sourceFile, $validation, $fallbackPo
            ));
        } finally {
            $workbook->close();
        }
    }

    private function readRows(
        Sheet $sheet,
        SourceFile $sourceFile,
        ValidationResult $validation,
        ?string $fallbackPo
    ): ImportResult {
        $headers = $validation->headers;
        $read = $imported = $skipped = 0;
        $warnings = [];
        $poNumbers = [];
        $fcs = [];
        $touchedPoIds = [];
        $totals = ['requested' => 0, 'accepted' => 0];

        foreach ($sheet->rows($validation->headerRow + 1) as $rowNumber => $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $read++;

            $asin = $headers->text($row, 'asin');
            $poNumber = $headers->text($row, 'po', 'po number') ?? $fallbackPo;

            // Every row of a real export has an ASIN; a row without one is a footer or
            // a stray note, not data.
            if ($asin === null || $poNumber === null) {
                $skipped++;

                continue;
            }

            $purchaseOrder = $this->upsertPurchaseOrder($poNumber, $row, $headers, $sourceFile);
            $touchedPoIds[$purchaseOrder->id] = true;
            $poNumbers[$poNumber] = true;

            if ($fc = $headers->text($row, 'ship to location', 'ship to')) {
                $fcs[$this->cleanFc($fc)] = true;
            }

            $requested = $headers->int($row, 'requested quantity', 'quantity requested') ?? 0;
            $accepted = $headers->int($row, 'accepted quantity', 'quantity accepted') ?? 0;

            $totals['requested'] += $requested;
            $totals['accepted'] += $accepted;

            PoLine::updateOrCreate(
                [
                    'marketplace' => Marketplace::Amazon->value,
                    'po_number' => $poNumber,
                    'sku_id' => $asin,
                ],
                [
                    'purchase_order_id' => $purchaseOrder->id,
                    'channel' => Channel::AmazonRetail,
                    'sku_id_type' => 'asin',
                    'title' => $headers->text($row, 'product name', 'title', 'description'),
                    'barcode' => $headers->text($row, 'external id'),
                    'external_id_type' => $headers->text($row, 'external id type'),
                    'model_number' => $headers->text($row, 'model number'),
                    'merchant_sku' => $headers->text($row, 'merchant sku'),
                    'availability' => $headers->text($row, 'availability'),
                    'qty_requested' => $requested,
                    'qty_accepted' => $accepted,
                    'qty_asn' => $headers->int($row, 'asn quantity') ?? 0,
                    'qty_received' => $headers->int($row, 'received quantity', 'quantity received') ?? 0,
                    'qty_remaining' => $headers->int($row, 'remaining quantity', 'quantity outstanding') ?? 0,
                    // Read-only information. NEVER used for netting - that is the
                    // cancellation file's job alone (§G).
                    'qty_cancelled_po_file' => $headers->int($row, 'cancelled quantity') ?? 0,
                    'case_size' => $headers->int($row, 'case size'),
                    'unit_cost' => $headers->decimal($row, 'unit cost', 'cost') ?? 0,
                    'currency' => $headers->text($row, 'currency') ?? 'AED',
                    'window_start' => $headers->date($row, 'window start'),
                    'window_end' => $headers->date($row, 'window end'),
                    'expected_date' => $headers->date($row, 'expected date'),
                    'ship_to_fc' => $purchaseOrder->ship_to_fc,
                    'product_id' => ProductIdentifier::resolveProductId(Marketplace::Amazon, $asin),
                    'source_file_id' => $sourceFile->id,
                    'imported_at' => now(),
                    'imported_by' => $sourceFile->uploaded_by,
                ]
            );

            $this->rememberIdentifier($asin, $row, $headers);

            $imported++;
        }

        if ($imported === 0) {
            throw new RuntimeException('No usable rows were found - every row was missing a PO number or an ASIN.');
        }

        // A packing list may have arrived before this PO. Link it up now (§K).
        $reconciled = $this->reconciler->attachOrphanShipmentLines(Marketplace::Amazon, array_keys($poNumbers));

        foreach (array_keys($touchedPoIds) as $poId) {
            $this->reconciler->recomputePurchaseOrder(PurchaseOrder::find($poId));
        }

        if ($reconciled > 0) {
            $warnings[] = "{$reconciled} packing-list line(s) that were waiting for these POs "
                .'have now been matched up.';
        }

        return new ImportResult(
            rowsRead: $read,
            rowsImported: $imported,
            rowsSkipped: $skipped,
            warnings: $warnings,
            summary: [
                'po_count' => count($poNumbers),
                'po_numbers' => array_keys($poNumbers),
                'fulfilment_centres' => array_keys($fcs),
                'units_requested' => $totals['requested'],
                'units_accepted' => $totals['accepted'],
                'confirmation_rate_pct' => $totals['requested'] > 0
                    ? round($totals['accepted'] / $totals['requested'] * 100, 2)
                    : null,
                'lines_reconciled' => $reconciled,
            ],
        );
    }

    /** PO-level facts repeat on every line; the first row of each PO wins. */
    private function upsertPurchaseOrder(string $poNumber, array $row, $headers, SourceFile $sourceFile): PurchaseOrder
    {
        if (isset($this->poCache[$poNumber])) {
            return $this->poCache[$poNumber];
        }

        $fc = $headers->text($row, 'ship to location', 'ship to');

        $purchaseOrder = PurchaseOrder::updateOrCreate(
            ['marketplace' => Marketplace::Amazon->value, 'po_number' => $poNumber],
            array_filter([
                'channel' => Channel::AmazonRetail,
                'vendor_code' => $headers->text($row, 'vendor code'),
                'status' => $headers->text($row, 'status'),
                'ship_to_raw' => $fc,
                'ship_to_fc' => $fc ? $this->cleanFc($fc) : null,
                'currency' => $headers->text($row, 'currency') ?? 'AED',
                'order_date' => $headers->date($row, 'order date'),
                'window_start' => $headers->date($row, 'window start'),
                'window_end' => $headers->date($row, 'window end'),
                'expected_date' => $headers->date($row, 'expected date'),
                'cancellation_deadline' => $headers->date($row, 'cancellation deadline'),
                'source_file_id' => $sourceFile->id,
                'imported_at' => now(),
                'imported_by' => $sourceFile->uploaded_by,
            ], fn ($v) => $v !== null)
        );

        return $this->poCache[$poNumber] = $purchaseOrder;
    }

    /**
     * Record that we have seen this ASIN, with its barcode and title, so search works
     * before the master catalog is loaded. product_id stays null until then (§S).
     */
    private function rememberIdentifier(string $asin, array $row, $headers): void
    {
        ProductIdentifier::updateOrCreate(
            ['marketplace' => Marketplace::Amazon->value, 'sku_id' => $asin],
            array_filter([
                'sku_id_type' => 'asin',
                'barcode' => $headers->text($row, 'external id'),
                'title' => $headers->text($row, 'product name', 'title', 'description'),
            ], fn ($v) => $v !== null)
        );
    }

    /**
     * Ship-to arrives as a code, sometimes with the address appended.
     * Take the leading code, e.g. "DXB3 - Dubai ..." -> "DXB3".
     */
    private function cleanFc(string $raw): string
    {
        $first = preg_split('/[\s,\-]+/', trim($raw))[0] ?? $raw;

        return strtoupper(substr($first, 0, 20));
    }

    /**
     * The single-PO export carries no PO column, so the number comes from the filename
     * or from what the user typed on the upload form (§C).
     */
    private function resolveSinglePoNumber(SourceFile $sourceFile): string
    {
        $supplied = data_get($sourceFile->summary, 'po_number');

        if (filled($supplied)) {
            return strtoupper(trim($supplied));
        }

        $fromFilename = self::poNumberFromFilename($sourceFile->original_filename);

        if ($fromFilename !== null) {
            return $fromFilename;
        }

        throw new RuntimeException(
            'This single-PO file has no PO column, and its filename does not contain a PO '
            .'number either. Re-upload it and type the PO number into the box on the '
            .'upload form.'
        );
    }

    /**
     * Amazon PO numbers are 8-character alphanumeric codes containing at least one
     * digit and one letter, e.g. 6QT4G44D, 774FV9FB. A plain word like
     * "PurchaseOrder" must not be mistaken for one.
     */
    public static function poNumberFromFilename(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        foreach (preg_split('/[^A-Za-z0-9]+/', $stem) ?: [] as $token) {
            if (strlen($token) === 8
                && preg_match('/^(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{8}$/', $token) === 1) {
                return strtoupper($token);
            }
        }

        return null;
    }
}
