<?php

namespace App\Services\Import;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Models\MasterAnomaly;
use App\Models\Product;
use App\Models\ProductChannelEconomics;
use App\Models\ProductIdentifier;
use App\Models\SourceFile;
use App\Services\Margin\NetMarginEngine;
use App\Services\Spreadsheet\Sheet;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\Importer;
use App\Services\Upload\ImportResult;
use App\Services\Upload\ValidationResult;
use Illuminate\Support\Facades\DB;

/**
 * The master catalog (§S) — one row per product x channel.
 *
 * This is the file that makes cross-channel reporting possible at all: it is what links
 * an ASIN and a NIN to one company product code, and it carries the unit economics every
 * margin figure is built from.
 *
 * THE RULE THAT SHAPES THIS CLASS: load everything, fix nothing, flag what looks wrong.
 * The sheet is merged from several sources and carries its own REVIEW notes; our own
 * checks find more. Every one of them is written to `master_anomalies` in plain language
 * and the row is loaded anyway. Guessing which of two products a reused code means, or
 * "correcting" a Noon identifier that holds an ASIN, would push a wrong cost into every
 * PO that SKU appears on and nobody would ever see it happen.
 *
 * Channel comes from the Customer Code, which is the only column that reliably says who
 * the row is about:
 *
 *     1F6RD        Amazon UAE - VC       -> amazon_retail   (ASINs)
 *     TY7WK        Amazon UAE - DS       -> amazon_dfs      (ASINs)
 *     LE3WVRU3GAE  Noon Retail           -> noon_retail     (NINs)
 *
 * Identifiers are stored per MARKETPLACE, economics per CHANNEL. That distinction is what
 * lets the 641 ASINs that sell on both Amazon Retail and Amazon DFS carry two sets of
 * numbers without duplicating the identifier or losing a channel.
 */
class MasterSheetImporter implements Importer
{
    /** Customer Code -> channel. Unknown codes are loaded and flagged, never dropped. */
    private const CHANNEL_BY_CUSTOMER_CODE = [
        '1F6RD' => Channel::AmazonRetail,
        'TY7WK' => Channel::AmazonDfs,
        'LE3WVRU3GAE' => Channel::NoonRetail,
    ];

    /** Anything we recognise as an Amazon ASIN. */
    private const ASIN_PATTERN = '/^B0[A-Z0-9]{8}$/i';

    /** Noon's NIN/ZSKU, e.g. Z8C550...Z-1. */
    private const NIN_PATTERN = '/^Z[A-Z0-9]+/i';

    private array $anomalies = [];

    private array $warnings = [];

    /** company_product_code => ['brand' => [...seen], 'product_cost' => [...seen]] */
    private array $seenAttributes = [];

    /** (marketplace|sku) => company_product_code, to catch two products claiming one id. */
    private array $claimedIdentifiers = [];

    /** (product_id|channel) already written this run, to catch duplicate rows. */
    private array $seenChannelRows = [];

    public function import(SourceFile $sourceFile, string $path, ValidationResult $validation): ImportResult
    {
        $this->anomalies = [];
        $this->warnings = [];
        $this->seenAttributes = [];
        $this->claimedIdentifiers = [];
        $this->seenChannelRows = [];

        $workbook = Workbook::open($path);

        try {
            $sheet = $workbook->sheet($validation->sheetName);

            $counts = DB::transaction(
                fn () => $this->readRows($sheet, $validation, $sourceFile)
            );
        } finally {
            $workbook->close();
        }

        // Link every fact row that has been waiting for this catalog (§K).
        $linked = $this->linkExistingFactRows();

        return new ImportResult(
            rowsRead: $counts['read'],
            rowsImported: $counts['imported'],
            rowsSkipped: $counts['skipped'],
            rowsUnmatched: 0,
            warnings: $this->warnings,
            summary: [
                'products' => Product::count(),
                'channel_rows' => ProductChannelEconomics::count(),
                'identifiers' => ProductIdentifier::count(),
                'anomalies_open' => MasterAnomaly::open()->count(),
                'anomalies_needing_review' => MasterAnomaly::needsReview()->count(),
                'po_lines_linked' => $linked['po_lines'] ?? 0,
                'shipment_lines_linked' => $linked['shipment_lines'] ?? 0,
            ],
        );
    }

    /** @return array{read:int, imported:int, skipped:int} */
    private function readRows(Sheet $sheet, ValidationResult $validation, SourceFile $sourceFile): array
    {
        $headers = $validation->headers;
        $read = $imported = $skipped = 0;

        foreach ($sheet->rows($validation->headerRow + 1) as $rowNumber => $row) {
            if (Sheet::isBlankRow($row)) {
                continue;
            }

            $code = trim((string) $headers->text($row, 'company product code'));

            // A row with no product code is not a product - a footer or a stray note.
            if ($code === '') {
                $skipped++;

                continue;
            }

            $read++;

            $product = $this->upsertProduct($code, $row, $headers, $sourceFile);
            $channel = $this->resolveChannel($row, $headers, $code, $product);

            if ($channel === null) {
                $skipped++;

                continue;
            }

            if ($this->isDuplicateChannelRow($product, $channel, $code, $rowNumber)) {
                $skipped++;

                continue;
            }

            $this->upsertIdentifier($product, $channel, $row, $headers, $code);
            $this->upsertEconomics($product, $channel, $row, $headers, $sourceFile);

            $imported++;
        }

        $this->raiseCrossChannelAnomalies();
        $this->persistAnomalies($sourceFile);

        return ['read' => $read, 'imported' => $imported, 'skipped' => $skipped];
    }

    // --- Products ---------------------------------------------------------

    /**
     * Create or update the product this row belongs to.
     *
     * A product appears once per channel, so this runs several times for the same code.
     * The first row wins for the shared attributes and later rows only fill blanks -
     * they never overwrite, because two channels disagreeing is a thing to report, not
     * a race for who was read last.
     */
    private function upsertProduct(string $code, array $row, $headers, SourceFile $file): Product
    {
        $product = Product::firstOrNew(['company_product_code' => $code]);

        $incoming = [
            'name' => $headers->text($row, 'product description'),
            'short_description' => $headers->text($row, 'product short description'),
            'brand' => $headers->text($row, 'brand'),
            'category' => $headers->text($row, 'category'),
            'sub_category' => $headers->text($row, 'sub category'),
            'owner' => $headers->text($row, 'owner'),
            'origin' => $headers->text($row, 'origin'),
            'barcode' => $headers->text($row, 'barcode'),
            'suppliers' => $headers->text($row, 'suppliers'),
            'cartons' => $headers->int($row, 'cartons'),
            'product_cost' => $headers->decimal($row, 'product cost'),
        ];

        // Remember what each channel claimed, so disagreements can be reported once at
        // the end rather than as a fresh anomaly per row.
        foreach (['brand', 'category', 'product_cost', 'suppliers'] as $field) {
            $value = $incoming[$field];

            if ($value !== null && $value !== '') {
                $this->seenAttributes[$code][$field][(string) $value] = true;
            }
        }

        foreach ($incoming as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // First row to carry a value sets it; later rows do not overwrite.
            if ($product->{$field} === null || $product->{$field} === '') {
                $product->{$field} = $value;
            }
        }

        if (! $product->exists) {
            $product->is_active = true;
            $product->source_file_id = $file->id;
            // §S interim rule, recorded per product so the Phase-3 switch to a weighted
            // average is a data migration rather than a rewrite.
            $product->cost_basis = config('operon.cost_basis', 'latest');
        }

        $product->cost_updated_at = now();
        $product->save();

        return $product;
    }

    // --- Channel ----------------------------------------------------------

    private function resolveChannel(array $row, $headers, string $code, Product $product): ?Channel
    {
        $customerCode = strtoupper(trim((string) $headers->text($row, 'customer code')));
        $channel = self::CHANNEL_BY_CUSTOMER_CODE[$customerCode] ?? null;

        if ($channel !== null) {
            return $channel;
        }

        // An unrecognised customer code is a new sales channel, or a typo. Either way a
        // person needs to say which, so the row is held rather than guessed into a
        // channel whose fees would then be applied to it.
        $this->flag(
            kind: MasterAnomaly::KIND_ATTRIBUTE_DISAGREEMENT,
            code: $code,
            product: $product,
            message: $customerCode === ''
                ? "Product {$code} has a row with no Customer Code, so there is no way to tell which channel it sells on. The product is in the catalog; this row's prices and fees are not."
                : "Customer Code \"{$customerCode}\" is not a channel OperON knows, so this row's prices and fees have not been loaded. The product itself is in the catalog. Add the code to the master importer's channel map if this is a new channel.",
            details: ['customer_code' => $customerCode,
                'customer_name' => $headers->text($row, 'customer name')],
        );

        return null;
    }

    private function isDuplicateChannelRow(Product $product, Channel $channel, string $code, int $rowNumber): bool
    {
        $key = $product->id.'|'.$channel->value;

        if (! isset($this->seenChannelRows[$key])) {
            $this->seenChannelRows[$key] = $rowNumber;

            return false;
        }

        $this->flag(
            kind: MasterAnomaly::KIND_DUPLICATE_ROW,
            code: $code,
            product: $product,
            channel: $channel,
            severity: MasterAnomaly::SEVERITY_NOTE,
            message: "Product {$code} appears twice for {$channel->label()} (rows {$this->seenChannelRows[$key]} and {$rowNumber}). The first row was loaded and the second ignored. If the two rows differ, the second one's figures have not been used.",
            details: ['first_row' => $this->seenChannelRows[$key], 'duplicate_row' => $rowNumber],
        );

        return true;
    }

    // --- Identifiers ------------------------------------------------------

    /**
     * Link the channel's native id (ASIN or NIN) to this product.
     *
     * Stored exactly as the file gives it. Where the shape is wrong for the channel -
     * the real file has a Noon row holding an ASIN - it is loaded as given AND flagged.
     * Moving it to Amazon would be a guess: it might be a typo, or it might be a genuine
     * Amazon listing filed under the wrong channel, and those need opposite fixes.
     */
    private function upsertIdentifier(Product $product, Channel $channel, array $row, $headers, string $code): void
    {
        $sku = trim((string) $headers->text($row, 'customer product code'));

        if ($sku === '') {
            return;
        }

        $marketplace = $channel->marketplace();
        $this->checkIdentifierShape($sku, $marketplace, $channel, $product, $code);

        $claimKey = $marketplace->value.'|'.$sku;
        $existingClaim = $this->claimedIdentifiers[$claimKey] ?? null;

        if ($existingClaim !== null && $existingClaim !== $code) {
            // Two different products claiming one identifier. The database will only hold
            // one, so the first stands and the clash is reported - silently reassigning
            // would move every PO line for that ASIN onto the wrong product.
            $this->flag(
                kind: MasterAnomaly::KIND_IDENTIFIER_CONFLICT,
                code: $code,
                product: $product,
                channel: $channel,
                message: "{$sku} is listed against two different products: {$existingClaim} and {$code}. It stays linked to {$existingClaim}. Until this is settled, anything sold under {$sku} is reported against {$existingClaim}.",
                details: ['identifier' => $sku, 'kept' => $existingClaim, 'rejected' => $code],
            );

            return;
        }

        $this->claimedIdentifiers[$claimKey] = $code;

        ProductIdentifier::updateOrCreate(
            ['marketplace' => $marketplace->value, 'sku_id' => $sku],
            [
                'product_id' => $product->id,
                'sku_id_type' => $marketplace === Marketplace::Amazon ? 'asin' : 'nin',
                'barcode' => $headers->text($row, 'barcode'),
                'title' => $headers->text($row, 'product description'),
            ]
        );
    }

    private function checkIdentifierShape(string $sku, Marketplace $marketplace, Channel $channel, Product $product, string $code): void
    {
        $looksLikeAsin = (bool) preg_match(self::ASIN_PATTERN, $sku);
        $looksLikeNin = (bool) preg_match(self::NIN_PATTERN, $sku);

        if ($marketplace === Marketplace::Noon && $looksLikeAsin) {
            $this->flag(
                kind: MasterAnomaly::KIND_IDENTIFIER_SHAPE,
                code: $code,
                product: $product,
                channel: $channel,
                message: "The {$channel->label()} row for {$code} holds {$sku}, which is an Amazon ASIN, where a Noon NIN belongs. It has been loaded as a Noon identifier exactly as the file has it, and not moved. Somebody needs to say whether this is a typo or a listing filed under the wrong channel - those need opposite fixes.",
                details: ['identifier' => $sku, 'expected' => 'NIN', 'looks_like' => 'ASIN'],
            );

            return;
        }

        if ($marketplace === Marketplace::Amazon && $looksLikeNin) {
            $this->flag(
                kind: MasterAnomaly::KIND_IDENTIFIER_SHAPE,
                code: $code,
                product: $product,
                channel: $channel,
                message: "The {$channel->label()} row for {$code} holds {$sku}, which looks like a Noon NIN, where an Amazon ASIN belongs. Loaded as given, not moved.",
                details: ['identifier' => $sku, 'expected' => 'ASIN', 'looks_like' => 'NIN'],
            );

            return;
        }

        // Neither shape - often the company's own BD code used as the channel id, which
        // is normal for products listed under an internal SKU. Worth knowing, not alarming.
        if (! $looksLikeAsin && ! $looksLikeNin) {
            $this->flag(
                kind: MasterAnomaly::KIND_IDENTIFIER_SHAPE,
                code: $code,
                product: $product,
                channel: $channel,
                severity: MasterAnomaly::SEVERITY_NOTE,
                message: "The {$channel->label()} row for {$code} uses \"{$sku}\" as its channel identifier, which is neither an ASIN nor a NIN. It has been loaded as given. Orders will only match this product if they arrive under that same id.",
                details: ['identifier' => $sku],
            );
        }
    }

    // --- Economics --------------------------------------------------------

    private function upsertEconomics(Product $product, Channel $channel, array $row, $headers, SourceFile $file): void
    {
        $economics = ProductChannelEconomics::firstOrNew([
            'product_id' => $product->id,
            'channel' => $channel->value,
        ]);

        $currency = $headers->text($row, 'currency');

        $economics->fill([
            'customer_code' => $headers->text($row, 'customer code'),
            'customer_name' => $headers->text($row, 'customer name'),
            'customer_product_code' => $headers->text($row, 'customer product code'),

            'rsp_with_vat' => $headers->decimal($row, 'rsp with vat'),
            'rsp_ex_vat' => $headers->decimal($row, 'rsp ex vat'),
            'invoice_cost_price' => $headers->decimal($row, 'invoice cost price'),

            'fulfilment_fee' => $headers->decimal($row, 'fulfilment fees'),
            'referral_fee' => $headers->decimal($row, 'referral fees'),
            'storage_fee' => $headers->decimal($row, 'storage fees'),
            'category_fee' => $headers->decimal($row, 'cat fees'),
            'other_fee' => $headers->decimal($row, 'other fees'),
            'platform_fees_pct' => $headers->decimal($row, 'platform total fees'),

            'product_cost' => $headers->decimal($row, 'product cost'),
            'marketing' => $headers->decimal($row, 'marketing'),
            'opex' => $headers->decimal($row, 'opex'),
            'packaging' => $headers->decimal($row, 'packaging cost'),
            'other_misc' => $headers->decimal($row, 'other misc expenses'),

            // Kept only to check our own arithmetic against (§10.9).
            // "Recievable" is the file's own spelling and is matched as it really
            // appears; the correct spelling is listed too so fixing the sheet is safe.
            'net_receivable_imported' => $headers->decimal($row, 'net recievable in hand', 'net receivable in hand', 'net receivable'),
            'cogs_imported' => $headers->decimal($row, 'cogs'),
            // Order matters: 'profit pct' must be tried before 'profit', or the
            // contains-match would hand back the plain Profit column for both.
            'profit_pct_imported' => $headers->decimal($row, 'profit pct'),
            'margin_pct_imported' => $headers->decimal($row, 'margin pct', 'margin'),
            'profit_imported' => $headers->decimal($row, 'profit'),

            // A currency cell holding "0" is not a currency. The packaging materials in
            // the real file are like this - things we buy and never sell.
            'currency' => ($currency !== null && preg_match('/^[A-Za-z]{3}$/', trim($currency)))
                ? strtoupper(trim($currency))
                : config('currencies.default'),

            'data_flag' => $headers->text($row, 'data flag'),
            'source_file_id' => $file->id,
            'is_manual' => false,
        ]);

        $economics->setRelation('product', $product);

        // Our own figures, computed from the inputs above - the source of truth (§S).
        NetMarginEngine::apply($economics);
        $economics->save();

        $this->checkFeeBasis($economics, $product, $channel);
        $this->checkAgainstSheet($economics, $product, $channel);
        $this->carryThroughFileFlag($economics, $product, $channel);
    }

    /**
     * The file's own REVIEW note, carried through in the merge tool's own words.
     *
     * We do not second-guess it and we do not act on it. It names something the person
     * who merged the sheets could not resolve either.
     */
    private function carryThroughFileFlag(ProductChannelEconomics $e, Product $product, Channel $channel): void
    {
        $flag = trim((string) $e->data_flag);

        if ($flag === '') {
            return;
        }

        // "differing supplier costs X / Y" means one code was found covering two
        // genuinely different products - the most consequential thing in the file,
        // because every margin for that code is built on whichever cost won.
        $isTwoProducts = str_contains(strtolower($flag), 'differing supplier cost');

        $this->flag(
            kind: $isTwoProducts ? MasterAnomaly::KIND_CODE_COVERS_TWO_PRODUCTS : MasterAnomaly::KIND_FILE_FLAG,
            code: $product->company_product_code,
            product: $product,
            channel: $channel,
            message: $isTwoProducts
                ? "The file flags {$product->company_product_code} on {$channel->label()} as one product code covering two different products — \"{$flag}\". One cost was chosen when the sheets were merged, and every margin for this code on this channel is built on it. Until somebody splits the two products apart, treat this SKU's profit as unreliable."
                : "The file flags {$product->company_product_code} on {$channel->label()}: \"{$flag}\". Carried through as-is; nothing has been changed on our side.",
            details: ['data_flag' => $flag],
        );
    }

    /** Both a fee breakdown and a headline percentage means we cannot tell which is meant. */
    private function checkFeeBasis(ProductChannelEconomics $e, Product $product, Channel $channel): void
    {
        $breakdown = (float) $e->fulfilment_fee + (float) $e->referral_fee
            + (float) $e->storage_fee + (float) $e->category_fee + (float) $e->other_fee;

        if ($breakdown > 0 && (float) $e->platform_fees_pct > 0) {
            $this->flag(
                kind: MasterAnomaly::KIND_FEE_BASIS_AMBIGUOUS,
                code: $product->company_product_code,
                product: $product,
                channel: $channel,
                message: "{$product->company_product_code} on {$channel->label()} gives fees twice: itemised (".number_format($breakdown, 2).') and as a percentage ('.round((float) $e->platform_fees_pct * 100, 2).'%). The percentage was used, because that is what the rest of the sheet is built on. If the itemised figures are the accurate ones, this product\'s margin is wrong.',
                details: ['breakdown_total' => round($breakdown, 4), 'platform_fees_pct' => (float) $e->platform_fees_pct],
            );
        }
    }

    /** Where our arithmetic and the sheet's disagree, say so (§10.9). */
    private function checkAgainstSheet(ProductChannelEconomics $e, Product $product, Channel $channel): void
    {
        if ($e->agreesWithSheet()) {
            return;
        }

        // Say which figure disagrees, because the two mean different things. A COGS gap
        // with no profit gap is the packaging-material case: the sheet totals their cost
        // as zero while the product plainly costs something.
        $parts = [];

        if ($e->cogsDrift() !== null && abs($e->cogsDrift()) > 0.01) {
            $parts[] = 'the cost per unit as '.number_format((float) $e->cogs_imported, 2)
                .' where we make it '.number_format((float) $e->cogs, 2);
        }

        if ($e->profitDrift() !== null && abs($e->profitDrift()) > 0.01) {
            $parts[] = 'the profit per unit as '.number_format((float) $e->profit_imported, 2)
                .' where we make it '.number_format((float) $e->profit, 2);
        }

        $this->flag(
            kind: MasterAnomaly::KIND_DERIVED_DISAGREEMENT,
            code: $product->company_product_code,
            product: $product,
            channel: $channel,
            severity: MasterAnomaly::SEVERITY_NOTE,
            message: "For {$product->company_product_code} on {$channel->label()}, the sheet gives "
                .implode(' and ', $parts).'. OperON reports its own figure (§S). '
                .'The gap usually means the sheet\'s inputs changed after its formulas last ran.',
            details: [
                'sheet_cogs' => $e->cogs_imported === null ? null : (float) $e->cogs_imported,
                'our_cogs' => $e->cogs === null ? null : (float) $e->cogs,
                'sheet_profit' => $e->profit_imported === null ? null : (float) $e->profit_imported,
                'our_profit' => $e->profit === null ? null : (float) $e->profit,
                'cogs_drift' => $e->cogsDrift(),
                'profit_drift' => $e->profitDrift(),
            ],
        );
    }

    // --- Cross-channel checks --------------------------------------------

    /**
     * Disagreements only visible once every channel for a product has been read.
     *
     * Casing is not a disagreement: "BRANDSFINITY" and "Brandsfinity" are the same brand
     * typed by two people, and reporting 73 of those would bury the three that matter.
     */
    private function raiseCrossChannelAnomalies(): void
    {
        foreach ($this->seenAttributes as $code => $fields) {
            $product = Product::where('company_product_code', $code)->first();

            foreach ($fields as $field => $values) {
                $distinct = array_keys($values);

                if ($field === 'product_cost') {
                    $numeric = array_unique(array_map(fn ($v) => (float) $v, $distinct));

                    if (count($numeric) > 1) {
                        sort($numeric);
                        $this->flag(
                            kind: MasterAnomaly::KIND_COST_DISAGREEMENT,
                            code: $code,
                            product: $product,
                            message: "{$code} is costed differently on different channels: ".implode(' and ', array_map(fn ($n) => number_format($n, 2), $numeric)).'. The catalog holds '.number_format((float) $product?->product_cost, 2).', which is the first figure read. Margin on the other channel is overstated or understated by the difference until somebody says which cost is right.',
                            details: ['costs' => array_values($numeric), 'using' => (float) $product?->product_cost],
                        );
                    }

                    continue;
                }

                // Text fields: ignore pure casing differences.
                $normalised = array_unique(array_map(fn ($v) => mb_strtolower(trim($v)), $distinct));

                if (count($normalised) > 1) {
                    $this->flag(
                        kind: MasterAnomaly::KIND_ATTRIBUTE_DISAGREEMENT,
                        code: $code,
                        product: $product,
                        severity: MasterAnomaly::SEVERITY_NOTE,
                        message: "{$code} has a different {$field} on different channels: ".implode(' / ', array_map(fn ($v) => "\"{$v}\"", $distinct)).'. The catalog holds "'.$product?->{$field}.'", which is the first one read. Reports that group by '.$field.' will use that one.',
                        details: [$field => $distinct, 'using' => $product?->{$field}],
                    );
                }
            }
        }
    }

    // --- Linking back ------------------------------------------------------

    /**
     * Attach every fact row that arrived before the catalog knew its SKU (§K).
     *
     * This is the moment the brand and category filters M5 built come alive: a PO line
     * with a product_id can be grouped by brand, and until now nothing had one.
     */
    private function linkExistingFactRows(): array
    {
        $linked = [];

        foreach (['po_lines', 'shipment_lines', 'cancellations', 'sellout_rows', 'dfs_orders'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $linked[$table] = DB::table($table)
                ->whereNull('product_id')
                ->update([
                    'product_id' => DB::raw(
                        '(SELECT pi.product_id FROM product_identifiers pi
                          WHERE pi.marketplace = '.$table.'.marketplace
                            AND pi.sku_id = '.$table.'.sku_id
                          LIMIT 1)'
                    ),
                ]);
        }

        return $linked + ['po_lines' => 0, 'shipment_lines' => 0];
    }

    // --- Anomaly plumbing --------------------------------------------------

    private function flag(
        string $kind,
        string $code,
        ?Product $product = null,
        ?Channel $channel = null,
        string $severity = MasterAnomaly::SEVERITY_REVIEW,
        string $message = '',
        array $details = [],
    ): void {
        $this->anomalies[] = [
            'kind' => $kind,
            'company_product_code' => $code,
            'product_id' => $product?->id,
            'channel' => $channel?->value,
            'severity' => $severity,
            'message' => $message,
            'details' => $details,
        ];

        if ($severity === MasterAnomaly::SEVERITY_REVIEW && count($this->warnings) < 12) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Write the run's findings.
     *
     * A re-upload replaces the previous run's open anomalies, so the queue reflects the
     * file as it stands now rather than accumulating every historical complaint. Anything
     * a person has already answered is left alone - that judgement is theirs and survives
     * a re-import.
     */
    private function persistAnomalies(SourceFile $file): void
    {
        MasterAnomaly::open()->delete();

        foreach ($this->anomalies as $anomaly) {
            MasterAnomaly::create($anomaly + ['source_file_id' => $file->id]);
        }

        $reviewCount = collect($this->anomalies)
            ->where('severity', MasterAnomaly::SEVERITY_REVIEW)->count();

        if ($reviewCount > count($this->warnings)) {
            $this->warnings[] = 'and '.($reviewCount - count($this->warnings))
                .' more item(s) needing review — see the Master catalog screen.';
        }
    }
}
