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
                sheetCandidates: ['Cancellations'],
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

            // --- Noon (§Q) ---------------------------------------------------
            new FileTypeDefinition(
                type: UploadType::NoonPo,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Packing List'],
                headerRowHint: 1,
                requiredHeaders: [
                    'NIN' => ['nin', 'zsku', 'sku id'],
                    'UOM Qty' => ['uom qty', 'uom quantity'],
                ],
                optionalHeaders: [
                    'GTIN' => ['gtin', 'barcode'],
                    'Seller SKU' => ['seller sku', 'sku'],
                    'Title' => ['title', 'description', 'item description'],
                    'Unit Cost' => ['unit cost', 'cost', 'price'],
                ],
                notes: 'Noon joins on NIN/ZSKU, not ASIN. Ordered quantity is "UOM Qty" from '
                    .'the Packing List tab; the PO number and Ship-To come from the '
                    .'PO-number-named metadata tab. "OG qty" is an internal reference and '
                    .'is ignored. Noon has no accept step, so no confirmation rate.',
            ),

            new FileTypeDefinition(
                type: UploadType::NoonInterimPicking,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Picking List'],
                headerRowHint: 1,
                requiredHeaders: [
                    'NIN' => ['nin', 'zsku', 'sku id'],
                    'Qty' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'Title' => ['title', 'description'],
                    'Seller SKU' => ['seller sku', 'sku'],
                ],
                notes: 'Noon interim. The delivery date is captured at upload (pre-filled with '
                    .'the metadata tab\'s Estimated Delivery Date, editable), because the '
                    .'Noon file does not reliably carry the real date.',
            ),

            new FileTypeDefinition(
                type: UploadType::NoonFinalPicking,
                extensions: ['xlsx', 'xls'],
                sheetCandidates: ['Picking List'],
                headerRowHint: 1,
                requiredHeaders: [
                    'NIN' => ['nin', 'zsku', 'sku id'],
                    'Qty' => ['qty', 'quantity'],
                ],
                optionalHeaders: [
                    'Invoice Number' => ['invoice number', 'invoice'],
                    'Title' => ['title', 'description'],
                    'Seller SKU' => ['seller sku', 'sku'],
                ],
                notes: 'Noon final. Fully-undelivered SKUs are deleted from the file, as on '
                    .'Amazon. Finals are summed per PO+NIN across deliveries, so the ~10% of '
                    .'POs that split into two ASNs need no special handling.',
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
                    'Customer Code' => ['customer code'],
                    'Customer Name' => ['customer name'],
                    'Description' => ['description', 'product description', 'title'],
                    'Brand' => ['brand'],
                    'Category' => ['category'],
                    'Barcode' => ['barcode', 'gtin', 'ean'],
                    'Invoice Cost Price' => ['invoice cost price'],
                    'Product Cost' => ['product cost'],
                    'RSP' => ['rsp', 'retail selling price'],
                    'Net Receivable' => ['net receivable'],
                    'Platform Fees %' => ['platform fees', 'platform fee'],
                    'Marketing' => ['marketing'],
                    'OPEX' => ['opex'],
                    'Packaging' => ['packaging'],
                    'COGS' => ['cogs'],
                    'Profit' => ['profit'],
                    'Margin %' => ['margin', 'margin percent'],
                ],
                expectedFilename: 'Master_Products_Sheet.xlsx',
                notes: 'Company Product Code (BD#####) is the canonical product key that '
                    .'unifies ASIN, NIN and DFS SKUs. Customer Product Code holds the '
                    .'channel-native id. Never link products by barcode.',
            ),
        ];
    }
}
