# PO Ops Dashboard — Technical Documentation

Written for: future maintainers/developers integrating this tool with the internal system currently being built in parallel.

## 1. What this is

Two standalone, single-file HTML applications. No server, no build step, no backend. All logic runs in the browser; all data lives in the browser's `localStorage` (plus manual JSON export/import for backup and transfer between machines).

- `PO_Ops_Dashboard.html` — full version, includes Margin/COGS tab behind a PIN gate (admin/owner use).
- `PO_Ops_Dashboard_Team.html` — identical feature set minus everything cost/margin-related (product cost fields, Margin tab, PIN-gate code). For ops staff / warehouse / accountants who shouldn't see COGS or margin.

Both files must be kept in sync by hand for any shared feature — there is no shared code module. When integrating with the internal tool, the natural next step is to extract the shared logic (parsers, reconciliation math, state model) into a single JS module both consume, or into the internal tool's backend, and drop this dual-file arrangement.

Dependencies (loaded from CDN, no local install):
- SheetJS `xlsx.full.min.js` v0.18.5 — reads `.xlsx`/`.xls` files client-side.
- Chart.js v3.9.1 — charts on Overview/Forecast tabs.

## 2. Data model (in-memory `state` object, persisted to `localStorage`) state = {
lineItems: {}, // key: marketplace|po_id|sku_id -> one row per PO line (from PO uploads)
shipmentLines: {}, // key: marketplace|po_id|sku_id|shipment|stage -> one row per packing-list line
cancelledItems: {}, // key: marketplace|po_id|sku_id -> one row per cancelled/at-risk line (Amazon only, currently)
masterSkuRows: {}, // key: marketplace|sku_id -> cross-reference row (barcode, titles, cost)
manualOverrides:{}, // key: marketplace|po|sku -> manual shipped-qty override
sourceFiles: [], // upload history/audit trail
accessAccounts: [], // admin file only: {email, pin} for the Margin tab gate (client-side only — not real security)
} `uidKey(marketplace, po, sku)` builds the `marketplace|po_id|sku_id` string used as the primary join key everywhere. This is the single most important convention in the codebase (see §4).

**Where each object type comes from and what's in it:**

| Object | Source upload | Key fields |
|---|---|---|
| `lineItem` | PO file (Amazon PO export or Noon PO-shaped file tagged "Purchase Order") | `marketplace, po_id, sku_id, sku_id_type, title, window_start, window_end, expected_date, qty_requested, qty_accepted, unit_cost, currency, fc_code` |
| `shipmentLine` | Packing list (Amazon Simple List, or Noon PO-shaped file tagged "Interim"/"Final") | `marketplace, po_id, sku_id, qty, stage ('interim'\|'final'), shipment_name, shipment_date` |
| `cancelledItem` | Amazon "Cancelled Items" / future-cancellation-risk export | `marketplace, po_id, sku_id, cancelled_qty (= file's "Quantity Outstanding"), quantity_confirmed, future_cancel_date` |
| `masterSkuRow` | Master SKU mapping file | `marketplace, sku_id, barcode, short_title, long_title, unit_cost (admin file only)` |

## 3. Upload flow

1. User drops/selects a file → `handleFiles()`.
2. `guessUploadType(wb, filename)` sniffs the workbook (sheet names, header keywords) and pre-fills a best guess for Marketplace (Amazon/Noon) and Document type (PO / Interim Packing List / Final Packing List / Cancelled Items / Master SKU Mapping).
3. **The guess is never auto-applied.** `maybeShowUploadTypeModal()` always shows a confirmation modal; the user must explicitly confirm or correct Marketplace + Document type before anything imports. This was a deliberate fix — silent auto-detect was causing wrong-template imports.
4. `confirmUploadType()` dispatches to the matching parser:
   - Amazon PO → `tryParseAmazonPO`
   - Amazon Packing List → `tryParseAmazonPackingList`
   - Amazon Cancelled Items → `tryParseAmazonCancelledItems`
   - Noon (any of PO/Interim/Final) → `tryParseNoon`, then `buildNoonLineItems` (if PO) or `buildNoonShipmentLines` (if Interim/Final) — **Noon has only one file layout**; the same parser handles all three Noon upload stages, only the stage tag chosen by the user differs.
   - Master SKU Mapping → `tryParseMasterSku`
5. If a file doesn't match any known shape, "map columns manually instead" opens `openMapModal()`, a generic column-mapper (`headerIndexMap`, `submitMapping`) as a fallback for unrecognized formats.
6. Parsed rows are ingested into `state` via `ingestLineItems` / `ingestShipmentLines` / `ingestCancelledItems` / `ingestMasterSkuRows`, each keyed so that **re-uploading the same PO/shipment/SKU overwrites the previous snapshot** (latest wins — no duplicate accumulation on re-upload).

## 4. Reconciliation logic — the join-key rule

**Rule: all reconciliation (matching a PO line to its shipment lines and cancelled-item lines) uses the native marketplace identifier — ASIN for Amazon, ZSKU/NIN for Noon — via `marketplace|po_id|sku_id`. Barcode is never used as a join key.**

Why: Excel silently strips leading zeros from numeric-formatted barcode/GTIN cells on export. A barcode like `0123456789012` becomes `123456789012` before this app (or any tool) ever reads the file — and inconsistently, depending on which export tool formatted the column as text vs. number. Two different real barcodes can even collide to the same value after zero-loss. This was traced directly to a real support issue (mismatched barcodes/titles/units on a packing-list upload) by inspecting a sample export and confirming the barcode column was stored as a raw Excel number.

Barcode is **only** used for:
- Display/search in the Master SKU table (search matches ASIN, ZSKU, or barcode interchangeably).
- `canonicalKeyFor(marketplace, sku_id)` — links an Amazon ASIN and a Noon ZSKU that are "the same physical product" for **cross-marketplace analytics rollups only** (SKU Analytics, ABC/XYZ, Margin tab). If no barcode is on file for a SKU, it falls back to its own native key (no cross-marketplace merge happens).
- A collision warning badge in the Master SKU table when two different SKUs in the *same* marketplace share a barcode (direct symptom detector for the zero-loss bug).

Reconciliation functions and what they do:
- `sumShipmentQty(marketplace, po, sku, stage)` — sums shipment-line qty for a PO+SKU, optionally filtered to one stage.
- `shippedQtyFor(marketplace, po, sku)` — the effective shipped quantity for fill-rate purposes. Priority: manual override → Final-stage packing list (authoritative/actually dispatched) → Interim-stage (processed but not yet confirmed dispatched). Never sums Final + Interim together (would double-count the same units).
- `cancelledQtyFor(marketplace, po, sku)` — looks up the cancelled quantity for a PO+SKU from `state.cancelledItems`.
- `fulfillmentRows(items)` — **the single central function** almost every tab's renderer consumes. For each PO line it computes: `qty_accepted = max(0, original_accepted − cancelled)`, then compares to `shippedQtyFor()` to derive `status` (`Not started / Partial / Complete / Over-shipped / Cancelled`) and `fill_rate`. Because cancellation-netting happens once here, it propagates automatically to every tab without needing separate handling per renderer.
- `computePOStatus(marketplace, po_id)` — PO-level rollup: pipeline stage (`Accepted → Processing → Dispatched`, derived from whether any line has interim/final shipment activity), overdue flag (window end passed and not fully dispatched and there's still non-cancelled qty outstanding), total accepted/cancelled/value net of cancellations.

## 5. Cancellation netting (Amazon Cancelled Items)

A cancelled unit is removed from what's owed, not counted as "missed." Concretely: `cancelledQtyFor()` feeds into both `fulfillmentRows()` (line-level) and `computePOStatus()` (PO-level), so:
- Fill-rate is computed against the net (non-cancelled) accepted quantity.
- A PO isn't flagged overdue solely because a cancelled line's window passed.
- A line with `net accepted qty ≤ 0` gets status `Cancelled` and drops out of the Pending tab.

Amazon's Cancelled Items export columns: `PO Number, ASIN, External ID, Title, Quantity Confirmed, Quantity Outstanding, Future Cancel Date`. `Quantity Outstanding` is treated as the cancelled/at-risk quantity.

## 6. Marketplace-specific parsing notes

**Amazon PO** (`tryParseAmazonPO`): looks for a sheet with columns matching `po, asin, expected quantity, unit cost`. FC (fulfillment center) code parsed out of the "Ship to location" column via `parseFC`.

**Amazon Packing List** (`tryParseAmazonPackingList`): prefers a sheet literally named "Simple List"; falls back to scanning all sheets. Shipment name/date are scraped from free-text rows above the header (`shipment name:` / `shipment date:` patterns), not fixed cells.

**Amazon Cancelled Items** (`tryParseAmazonCancelledItems`): requires `po number, asin, quantity outstanding` columns.

**Noon** (`tryParseNoon`): Noon's PO-shaped export has a metadata block (Partner Code, P.O No, Date, Estimated Delivery, Ship To, etc.) in the first ~15 rows with **no fixed column position** — label and value aren't always adjacent (merged cells). `findLabelValue()` scans for a label-matching cell and returns the first non-empty cell to its right in the same row to handle this. The item table itself starts after that block; required columns are `NINs, GTIN, UOM Qty`. `NINs` (Noon Item Number) is the native platform ID, treated as the ZSKU-equivalent join key throughout. FC code is extracted from the free-text "Ship To" field via regex (`extractFcCodeFromShipTo`) since Noon doesn't provide it as a separate column.

Noon has no separate packing-list template — the same PO-shaped file is re-uploaded later and tagged "Interim" or "Final" at upload-type-confirmation time. `tryParseNoon` is stage-agnostic; `buildNoonLineItems` vs `buildNoonShipmentLines` decide what the parsed rows become.

## 7. Master SKU list — format to request from the business

Columns, in order of importance:
1. **Marketplace-native SKU ID** — ASIN (Amazon) or ZSKU/NIN (Noon). One row per SKU per marketplace (a product sold on both marketplaces needs two rows, linked by barcode).
2. **Barcode** — must be formatted as **Text** in Excel before export, not General/Number, or leading zeros are lost permanently. This is the #1 failure mode seen so far.
3. **Short title** — for compact table display.
4. **Long/marketplace title** — optional, auto-detected if present.
5. **Product cost** — optional; only meaningful/used in the admin file (Margin tab). Omit entirely for the Team file's master list, or leave blank — the Team file's parser and renderers don't read or display it.

The importer auto-detects columns by header-name pattern matching (`barcode`, `asin`, `zsku`/`noon`, `short title`, `product cost`, etc.) — see `tryParseMasterSku`. Column order in the source file doesn't matter, header text does (case-insensitive, fuzzy match).

## 8. Tabs / renderers and what feeds them

All of these consume `fulfillmentRows(filteredLineItems())` (or `allLineItems()`), so cancellation-netting and status are already applied before rendering:

- **Overview** (`renderOverview`) — top-line KPIs/charts.
- **Fulfillment** (`renderFulfillment`) — line-level status table, manual override entry point (`setManualOverride`/`clearOverride`).
- **PO Status / Calendar** (`renderCalendar`) — PO-level pipeline stage + overdue flags via `computePOStatus`/`allPOStatuses`.
- **Pending** (`renderPending`) — lines not yet Complete/Cancelled.
- **SKU Analytics** (`renderSku`) — cross-marketplace rollup via `canonicalKeyFor` (barcode-based merge where available).
- **Forecast / ABC-XYZ** (`renderForecast`, `renderAbcXyz`) — monthly trend + classification, also canonical-key based.
- **Margin** (`renderMarginTab`, admin file only, PIN-gated) — adds cost/price/margin math on top of the same fulfillment rows.
- **Master SKU** (`renderMasterList`) — the cross-reference table itself, with the barcode-collision warning.
- **Cancelled Items** (`renderCancelledList`) — raw list of ingested cancellations.

## 9. Known limitations / things to flag to the internal-tool team

- No backend, no multi-user concurrency — `localStorage` is per-browser-profile. Two people on two machines have two independent copies unless they exchange JSON export/import backups.
- The admin/Team split is a manual code fork, not a permissions layer — anyone who opens the admin HTML file with the right PIN sees margin data. There's no server-side access control.
- Amazon's PO export only ever shows already-accepted POs (no pre-acceptance "Received" stage is observable from the data), so PO stage tracking starts at "Accepted."
- Barcode-based cross-marketplace linking is only as good as the barcode data supplied — see §7. If a barcode is missing or wrong for a SKU, that SKU just won't merge into the unified rollup (safe failure mode — no incorrect merge).

## 10. Open feature requests (not yet built, for context)

Tracked separately with the business owner:
- Delivery-batch/sub-order tracking within a single PO (visibility for warehouse supervisor into which line items belong to which delivery batch, e.g. "order 1 of 6").
- An in-dashboard assistant to draft replies to Amazon delivery-confirmation emails from pasted email text or a screenshot.
- Extended Master SKU / reporting schema: supplier, buy price, sell price, marketing allocation, for richer margin/ops reports.
