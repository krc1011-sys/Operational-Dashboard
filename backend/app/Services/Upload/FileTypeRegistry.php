<?php

namespace App\Services\Upload;

use App\Enums\UploadType;

/**
 * The §T file registry, as code.
 *
 * Every column alias here comes from the blueprint's verified descriptions of the real
 * files. They are deliberately generous - the same column appears as "PO", "PO Number"
 * and "P.O No" across the three tools - and they are CONFIRMED AGAINST THE REAL FILES
 * AT M3, which is exactly what the M3 checkpoint is for.
 */
class FileTypeRegistry
{
    /** @return array<string, FileTypeDefinition> keyed by UploadType value */
    public static function all(): array
    {
        static $definitions = null;

        return $definitions ??= collect(self::build())
            ->keyBy(fn (FileTypeDefinition $d) => $d->type->value)
            ->all();
    }

    public static function for(UploadType $type): FileTypeDefinition
    {
        return self::all()[$type->value];
    }

    /** @return FileTypeDefinition[] */
    private static function build(): array
    {
        return [

            // --- Amazon PO, bulk export (§C) --------------------------------
            new FileTypeDefinition(
                type: UploadType::AmazonPoBulk,
                extensions: ['xls', 'xlsx'],
                sheetCandidates: ['Line Items'],
                headerRowHint: 1,
                requiredHeaders: [
                    'PO' => ['po', 'po number', 'purchase order'],
                    'ASIN' => ['asin'],
                    'Accepted quantity' => ['accepted quantity', 'quantity accepted', 'accepted'],
                    'Requested quantity' => ['requested quantity', 'quantity requested', 'requested'],
                    'Ship-to location' => ['ship to location', 'ship to', 'fulfillment center', 'fulfilment center'],
                ],
                optionalHeaders: [
                    'Vendor code' => ['vendor code', 'vendor'],
                    'Order date' => ['order date', 'ordered on', 'po date'],
                    'Status' => ['status'],
                    'Product name' => ['product name', 'product title', 'title', 'description'],
                    'External ID type' => ['external id type'],
                    'External ID' => ['external id'],
                    'Model number' => ['model number'],
                    'Merchant SKU' => ['merchant sku'],
                    'Availability' => ['availability'],
                    'ASN quantity' => ['asn quantity'],
                    'Received quantity' => ['received quantity'],
                    'Cancelled quantity' => ['cancelled quantity', 'canceled quantity'],
                    'Remaining quantity' => ['remaining quantity'],
                    'Window start' => ['window start', 'delivery window start'],
                    'Window end' => ['window end', 'delivery window end'],
                    'Case size' => ['case size'],
                    'Unit cost' => ['unit cost', 'cost'],
                    'Currency' => ['currency'],
                    'Expected date' => ['expected date'],
                    'Cancellation deadline' => ['cancellation deadline'],
                ],
                expectedFilename: 'POItemExport_<date>.xls',
                notes: 'The standard twice-weekly operational upload. One row per PO x ASIN, '
                    .'many POs and FCs in one file. Upload it exactly as Amazon sends it - '
                    .'do not convert to CSV, which worsens the leading-zero loss on barcodes.',
            ),

            // --- Amazon PO, single-PO export (§C) ---------------------------
            new FileTypeDefinition(
                type: UploadType::AmazonPoSingle,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Purchase Order'],
                headerRowHint: 1,
                requiredHeaders: [
                    'ASIN' => ['asin'],
                    'Accepted quantity' => ['accepted quantity', 'quantity accepted', 'accepted'],
                ],
                optionalHeaders: [
                    'Requested quantity' => ['requested quantity', 'quantity requested'],
                    'Product name' => ['product name', 'title', 'description'],
                    'External ID' => ['external id'],
                    'Model number' => ['model number'],
                    'Unit cost' => ['unit cost', 'cost'],
                ],
                expectedFilename: 'PurchaseOrder.xlsx',
                notes: 'The secondary single-PO format. It carries no PO or Ship-to column, '
                    .'so the PO number is taken from the filename or the sheet header.',
            ),

            // --- Amazon packing lists, interim and final (§K) ----------------
            // Same tab, same header names; the final shifts every column right, which is
            // why both share one definition and everything is matched by name.
            new FileTypeDefinition(
                type: UploadType::AmazonInterimPacking,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Simple List'],
                headerRowHint: 4,
                requiredHeaders: [
                    'PO' => ['po', 'po number'],
                    'ASIN' => ['asin'],
                    'Qty' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'Model Number' => ['model number', 'barcode'],
                    'Title' => ['title', 'short title', 'description'],
                    'Carton' => ['carton', 'carton number', 'carton no'],
                    'Unit Cost' => ['unit cost', 'cost'],
                ],
                expectedFilename: 'PACKING LIST_<ASN>-<ref> - Interim.xlsx',
                notes: 'Read ONLY the "Simple List" tab - the "Short Titles" and "Packing List" '
                    .'tabs are ignored. Rows whose Title is literally "Carton total" are '
                    .'per-carton subtotals and are skipped, or units double-count. The ASN '
                    .'is parsed out of the "Shipment Name" banner.',
            ),

            new FileTypeDefinition(
                type: UploadType::AmazonFinalPacking,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Simple List'],
                headerRowHint: 4,
                requiredHeaders: [
                    'PO' => ['po', 'po number'],
                    'ASIN' => ['asin'],
                    'Qty' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'Invoice Number' => ['invoice number', 'invoice no', 'invoice'],
                    'Model Number' => ['model number', 'barcode'],
                    'Title' => ['title', 'short title', 'description'],
                    'Carton' => ['carton', 'carton number', 'carton no'],
                    'Unit Cost' => ['unit cost', 'cost'],
                    'Line Value' => ['line value', 'value', 'total'],
                ],
                expectedFilename: 'PACKING LIST_<ASN>-<ref> - Final.xlsx',
                notes: 'Same tab as the interim, with columns shifted and Unit Cost now visible. '
                    .'Carries one Invoice Number per PO for the accounts link, has no '
                    .'"Carton total" rows, and no zero-quantity lines. Final Qty is the '
                    .'authoritative shipped figure that drives fill rate.',
            ),

            // --- Amazon cancellations - the one template we build (§G, §T) ---
            new FileTypeDefinition(
                type: UploadType::AmazonCancellations,
                extensions: ['xlsx', 'xls'],
                // The generated template names its tab "Cancellations"; the mock the team
                // currently pastes into is a plain "Sheet1". Both are accepted.
                sheetCandidates: ['Cancellations', 'Sheet1'],
                headerRowHint: 1,
                requiredHeaders: [
                    'PO Number' => ['po number', 'po'],
                    'ASIN' => ['asin'],
                    'Quantity Cancelled' => ['quantity cancelled', 'quantity canceled', 'cancelled quantity'],
                ],
                optionalHeaders: [
                    'External ID' => ['external id', 'barcode'],
                    'Description' => ['description', 'title', 'product name'],
                    'Quantity Confirmed' => ['quantity confirmed', 'confirmed quantity'],
                ],
                expectedFilename: 'Amazon_Cancellations_<YYYY-MM-DD>.xlsx',
                notes: 'The only user-built template. Cancellations arrive by email and are '
                    .'pasted into this sheet. THIS FILE IS THE SOLE SOURCE OF NETTING - the '
                    .'PO export\'s own "Cancelled quantity" column is never used for it.',
            ),

            // --- Amazon sell-out (§P) ---------------------------------------
            new FileTypeDefinition(
                type: UploadType::AmazonSellout,
                extensions: ['xlsx', 'xls', 'csv'],
                sheetCandidates: [],
                headerRowHint: 2, // row 1 is metadata: Viewing Range, Currency, View By
                requiredHeaders: [
                    'ASIN' => ['asin'],
                    'Shipped Units' => ['shipped units'],
                ],
                optionalHeaders: [
                    'Product Title' => ['product title', 'title'],
                    'Brand' => ['brand'],
                    'Shipped Revenue' => ['shipped revenue'],
                    'Shipped COGS' => ['shipped cogs'],
                    'Customer Returns' => ['customer returns', 'returns'],
                ],
                expectedFilename: 'Sales_ASIN_Sourcing_Retail_...xlsx',
                notes: 'Sell-out: what Amazon sold on to end customers. Row 1 holds the '
                    .'reporting window metadata; the header is row 2. Some rows carry only '
                    .'returns with blank sales.',
            ),

            // --- Amazon DFS (§R) --------------------------------------------
            new FileTypeDefinition(
                type: UploadType::AmazonDfs,
                extensions: ['xlsx', 'xls', 'csv'],
                sheetCandidates: [],
                headerRowHint: 1,
                requiredHeaders: [
                    'order id' => ['order id', 'order'],
                    'ASIN' => ['asin'],
                    'QTY' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'Invoice number' => ['invoice number', 'invoice'],
                    'Invoice date' => ['invoice date'],
                    'SKU' => ['sku', 'seller sku'],
                    'Item Description' => ['item description', 'description'],
                    'Invoice amount' => ['invoice amount', 'amount'],
                ],
                expectedFilename: 'DFS_<YYYY-MM>.xlsx',
                notes: 'Direct Fulfilment: real end-customer orders we fulfil from our own '
                    .'stock. No PO and no fill rate - a revenue feed by ASIN. Outbound only.',
            ),

            // --- Noon (§Q, M8) -----------------------------------------------
            //
            // CONFIRMED AGAINST THE REAL FILE (PO 287285145169960). Every Noon workbook
            // carries the SAME four tabs whichever stage it is - Short Titles, a tab
            // NAMED FOR THE PO, Packing List and Picking List - so the tab a definition
            // reads is what distinguishes the types, not the filename (§T).
            //
            // NOON'S NAMING IS THE REVERSE OF AMAZON'S: "Packing List" is the ORDER and
            // "Picking List" is the DELIVERY.
            new FileTypeDefinition(
                type: UploadType::NoonPo,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Packing List'],
                headerRowHint: 1,
                requiredHeaders: [
                    'NINs' => ['nins', 'nin', 'zsku', 'sku id'],
                    'UOM Qty' => ['uom qty', 'uom quantity'],
                ],
                optionalHeaders: [
                    'GTIN' => ['gtin', 'barcode'],
                    'Seller Sku' => ['seller sku', 'sku'],
                    'Product Title' => ['product title', 'title', 'description'],
                    'Model Number' => ['model number'],
                    'Category' => ['category'],
                    'Brand' => ['brand'],
                    'Size' => ['size'],
                    'COO' => ['coo', 'country of origin'],
                    'Unit Rate' => ['unit rate', 'unit cost', 'cost'],
                    'Vat' => ['vat'],
                    'Final Cost' => ['final cost'],
                    'Total Amount' => ['total amount', 'line value'],
                ],
                notes: 'On Noon the PACKING LIST is the order. Ordered quantity is "UOM Qty"; '
                    .'the PO number, ship-to, currency and dates come from the tab named for '
                    .'the PO itself. Joins to the catalog on NIN, which is the master sheet\'s '
                    .'"Customer Product Code (Noon)". Noon has no accept step, so there is no '
                    .'confirmation rate and accepted always equals ordered.',
            ),

            // Interim and final share one shape, and the two layouts genuinely differ:
            // the interim is 7 columns with Barcodes in column 3, the final is 10 with
            // Barcodes in column 4, an unlabelled consignment reference in column 3 and
            // an "OG qty" column that is filled in ONLY on a short line. Which is exactly
            // why every column here is found by name (§K).
            new FileTypeDefinition(
                type: UploadType::NoonInterimPicking,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Picking List'],
                headerRowHint: 1,
                requiredHeaders: [
                    'Barcodes' => ['barcodes', 'barcode'],
                    'Qty' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'NINs' => ['nins', 'nin', 'zsku'],
                    'Short Title' => ['short title', 'title', 'description'],
                    'Unit Rate' => ['unit rate', 'unit cost'],
                    'Match Key' => ['match key'],
                    'OG qty' => ['og qty', 'original qty', 'og quantity'],
                ],
                notes: 'Noon interim. Optional - a Noon PO is one-shot and may go straight to '
                    .'a final. The delivery date is typed on the upload form, pre-filled with '
                    .'the metadata tab\'s Estimated Delivery Date, because the file carries no '
                    .'real one. An EMPTY picking tab is valid and means every line went out '
                    .'in full.',
                // Noon annotates only exceptions, so no rows = no exceptions (§Q).
                allowsNoDataRows: true,
            ),

            new FileTypeDefinition(
                type: UploadType::NoonFinalPicking,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Picking List'],
                headerRowHint: 1,
                requiredHeaders: [
                    'Barcodes' => ['barcodes', 'barcode'],
                    'Qty' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'NINs' => ['nins', 'nin', 'zsku'],
                    'Short Title' => ['short title', 'title', 'description'],
                    'Unit Rate' => ['unit rate', 'unit cost'],
                    'Match Key' => ['match key'],
                    'OG qty' => ['og qty', 'original qty', 'og quantity'],
                    'Invoice Number' => ['invoice number', 'invoice'],
                ],
                notes: 'Noon final - what was actually delivered. NOON ANNOTATES ONLY THE '
                    .'EXCEPTIONS: a line missing from this tab was delivered IN FULL, and only '
                    .'a short line is listed with its "OG qty". Reading it as a positive record '
                    .'the way an Amazon packing list is read understates the fill rate badly. '
                    .'An EMPTY tab is valid and means the whole PO went out in full.',
                allowsNoDataRows: true,
            ),

            // --- Master products sheet (§S) ----------------------------------
            new FileTypeDefinition(
                type: UploadType::MasterSheet,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: [],
                headerRowHint: 1,
                requiredHeaders: [
                    'Company Product Code' => ['company product code'],
                    'Customer Product Code' => ['customer product code'],
                ],
                optionalHeaders: [
                    /*
                     * These aliases are confirmed against the real merged master, which is
                     * why several of them carry the file's own spellings: "Referal Fees",
                     * "Warehosue / Storage Fees" and "Net Recievable in Hand" are typos in
                     * the source and are matched as they actually appear. The correct
                     * spellings are listed too, so fixing the sheet will not break the
                     * upload either.
                     */
                    'Customer Code' => ['customer code'],
                    'Customer Name' => ['customer name'],
                    'Product Description' => ['product description', 'description', 'title'],
                    'Product Short Description' => ['product short description', 'short description'],
                    'Brand' => ['brand'],
                    'Category' => ['category'],
                    'Sub Category' => ['sub category', 'subcategory', 'apl sub category'],
                    'Owner' => ['owner', 'apl owner'],
                    'Origin' => ['origin', 'apl origin', 'country of origin'],
                    'Barcode' => ['barcode', 'gtin', 'ean'],
                    'Suppliers' => ['suppliers', 'supplier'],
                    'Cartons' => ['cartons', 'carton'],

                    'RSP with VAT' => ['rsp with vat', 'rsp inc vat', 'rsp including vat'],
                    'RSP ex VAT' => ['rsp with without vat', 'rsp without vat', 'rsp ex vat', 'rsp'],
                    'Invoice Cost Price' => ['invoice cost price'],

                    'Fulfilment Fees' => ['fulfilment fees', 'fulfillment fees'],
                    'Referral Fees' => ['referal fees', 'referral fees'],
                    'Storage Fees' => ['warehosue storage fees', 'warehouse storage fees', 'storage fees'],
                    'Cat Fees' => ['cat fees', 'category fees'],
                    'Other Fees' => ['other fees'],
                    'Platform Total Fees' => ['platform total fees', 'platform fees', 'platform fee'],

                    'Product Cost' => ['product cost'],
                    'Marketing' => ['marketing'],
                    'OPEX' => ['opex'],
                    'Packaging Cost' => ['packaging cost', 'packaging'],
                    'Other Misc Expenses' => ['other misc expenses', 'other miscellaneous expenses'],

                    'Net Receivable' => ['net recievable in hand', 'net receivable in hand', 'net receivable'],
                    'COGS' => ['cogs cost of goods sold', 'cogs', 'cost of goods sold'],
                    'Profit' => ['profit'],
                    'Profit pct' => ['profit percent', 'profit %'],
                    'Margin pct' => ['margin percent', 'margin %', 'margin'],

                    'Currency' => ['currency'],
                    'Data Flag' => ['data flag', 'data_flag', 'flag'],
                ],
                expectedFilename: 'OperON_Master_Merged.xlsx',
                notes: 'One row per PRODUCT x CHANNEL. Company Product Code (BD#####) is the '
                    .'canonical product key that unifies ASIN, NIN and DFS SKUs; Customer '
                    .'Product Code holds the channel-native id and Customer Code says which '
                    .'channel. Never link products by barcode. Rows the sheet has flagged for '
                    .'review are loaded and listed on the Master screen, never silently fixed.',
            ),
        ];
    }
}
