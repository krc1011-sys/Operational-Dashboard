# OperON — Logic Blueprint (living spec)

Status: **in progress** — updated as Karan walks through each file. Nothing here is
built until Karan types `EXECUTE`. This is the agreed source of truth for the build.

Covered so far: (1) Amazon PO file, (2) ISA concept, (3) cancellations.
Next up: (4) interim packing list, (5) final packing list.

---

## A. The chain of events (business flow)

1. **Amazon sends POs** (Mon/Wed + ad-hoc add-ons). One export file = **many POs**.
2. Team **schedules deliveries**; each booked delivery = an **ISA** (Amazon appointment ID).
3. **One ISA = exactly one fulfillment center**, and can bundle **several POs** (never POs
   from different FCs).
4. Procurement orders from suppliers; a **packing list per ISA** is produced.
5. Packing list has two stages: **Interim** (planned in the ISA) → **Final** (actually shipped).
6. A single PO-line's accepted units can be **split across multiple ISAs** over time.

## B. Identifiers (locked)

- **ASIN is the unique join key** for Amazon. Every match (PO ↔ packing list ↔ cancellation)
  is on **PO number + ASIN**.
- **Barcode is NEVER a join key** — it's unreliable (leading zeros get dropped; the sample
  file shows the same barcode as `634562947130` in one column and `0634562947130` in another,
  and the "type" varies: UPC / EAN / SKU / ISBN). Barcode + title are stored for
  **display and search only**. Team can search by **ASIN, barcode, or title**.

---

## C. File 1 — Amazon PO export (the real file, verified)

- Real format is **`.xls`** (Excel 97–2003), sheet named **"Line Items"** (+ "Instructions",
  "Reference Data" sheets we ignore). The uploader **must accept `.xls`** (not just `.xlsx`).
- Upload it **as Amazon sends it — no manual conversion**. (Avoid CSV — it worsens the
  leading-zero loss on barcodes.)
- One row per **PO × ASIN**. Sample: 126 lines, 10 POs, 7 FCs, every row has an ASIN.
- Columns → what we store:

  | Amazon column        | We store as        | Notes |
  |----------------------|--------------------|-------|
  | PO                   | po_number          | join key part |
  | Vendor code          | vendor_code        | e.g. 1F6RD |
  | Order date           | order_date         | the "PO open date" |
  | Status               | status             | e.g. Confirmed |
  | Product name         | title              | for display/search |
  | ASIN                 | asin               | **join key** |
  | External ID type     | external_id_type   | UPC/EAN/SKU/ISBN |
  | External ID          | barcode            | display/search only |
  | Model number         | model_number       | |
  | Merchant SKU         | merchant_sku       | often blank |
  | Availability         | availability       | e.g. "AC - Accepted: In stock" |
  | Requested quantity   | requested_qty      | |
  | **Accepted quantity**| **accepted_qty**   | **fill-rate denominator** |
  | ASN quantity         | asn_qty            | |
  | Received quantity    | received_qty       | |
  | Cancelled quantity   | po_file_cancelled_qty | see open Q1 |
  | Remaining quantity   | remaining_qty      | |
  | Ship-to location     | ship_to_fc         | clean FC code (e.g. DXB3) |
  | Window start / end   | window_start/end   | |
  | Case size            | case_size          | |
  | Cost                 | unit_cost          | marketplace price |
  | Currency             | currency           | AED |
  | Expected date        | expected_date      | |
  | Cancellation deadline| cancellation_deadline | |
  | (Total * cost cols)  | (optional)         | can be recomputed |

- **Re-upload:** upsert on (amazon, po_number, asin) — latest snapshot wins, no duplicates.
  New PO files over time **accumulate** POs into the system.
- **Two Amazon PO export formats exist** → header-name mapping handles both: (1) **bulk "PO
  Item Export"** (`.xls`, many POs, has PO/Vendor/Ship-to/Requested/Accepted) = the standard
  twice-weekly operational upload (CONFIRMED norm; sometimes just 1 PO / single-FC — handled
  fine); (2) **single-PO "PurchaseOrder.xlsx"** (one PO, no PO/FC columns — PO# from filename)
  = secondary. Karan updates POs continuously as received (no data loss).

---

## D. File 2 — ISA appointment letter (PDF) — REFERENCE ONLY, not ingested

- Confirms the ISA model (sample ISA 4949580591 → FC DXB3, PO List [774FV9FB, …],
  7 pallets / 144 cartons) but has **no ASINs or quantities**.
- Decision: **do NOT parse this PDF.** The packing list carries the ISA number + all detail.
  Pallet/carton counts are a "nice to have" we can add later via manual entry if wanted.

---

## E. Fill rate (locked)

- **Fill rate (final) = Σ final-shipped units (across all ISAs) ÷ net-accepted units.**
- Net accepted = accepted − honored cancellations.
- Accumulates across deliveries: e.g. accepted 2,000; finals 480+500+700+300 = 1,980 → **99%**.
- **VALIDATED on real 8-ISA PO** (~AED 234k, 87 ASINs, 14,740 accepted): summed 14,117 across
  8 ISAs → 95.77% fill; PO↔ASIN join 0 unmatched / 0 over-shipped; 623-unit shortfall isolated
  to 3 ASINs (2 partial + 1 unshipped). Multi-ISA aggregation + reconciliation + shortfall
  pinpointing all confirmed on genuine data.

---

## F. Per-line status model (PO × ASIN row)

Show **separate visible numbers in the row**: **Accepted · Booked · Not-booked · Cancelled**
(· Shipped · Fill% once finals arrive). State labels derived from those:

| State | When |
|---|---|
| **Cancelled** | whole line cancelled |
| **Not yet booked** | 0 units in any interim packing list |
| **Scheduled** | some units in an interim packing list (booked into an ISA) |
| **Dispatched** | units in a final packing list (actually shipped) |

- **Booked** = units appearing in **interim** packing lists.
- **Dispatched/Shipped** = units in **final** packing lists.
- **Not-booked** = net accepted − booked − cancelled.
- A line can span states at once — the row shows the real numbers side by side.

---

## G. Cancellations (locked)

- Source: **email only** today. Karan **pastes into an Excel sheet and uploads it**.
  → **Template CONFIRMED** (validated against Karan's mock `Cancelled items_02.08.xlsx`):
  `PO Number · ASIN · External ID · Description · Quantity Confirmed · Quantity Cancelled`.
  Net by **Quantity Cancelled**, join on **PO + ASIN**; use **Quantity Confirmed** as a
  cross-check vs our stored accepted qty (warn on mismatch). Only standardise the filename +
  sheet name; keep the columns exactly as-is.
- **Single source of truth = the uploaded cancellation file.** The PO export's own
  "Cancelled quantity" column is **NOT used for netting** (fresh POs always show 0; real
  cancellations only arrive days later by email). We store/show it as read-only info only.
- Base rule: cancellation reduces **only not-yet-shipped** units → lowers net accepted.
- **Anomaly (flag + ask):** if a cancellation would claw back units **already booked into an
  ISA or already shipped**, the system **stops and asks**:
  - **Deliver anyway** → disregard the cancellation for those units (they stay accepted and
    count as delivered — line can read 100%), **but show a warning flag**
    "cancellation received — shipped anyway" (chargeback exposure).
  - **Pull it** → remove from the ISA / reduce as normal (only possible if not yet booked/shipped).
- Amazon's own email confirms this: *"If you have shipped these items, disregard this message…
  items shipped after this notification are subject to chargebacks."*

---

## H. Open questions (to resolve before EXECUTE)

1. ~~Two cancellation sources~~ **RESOLVED:** rely solely on the uploaded cancellation
   file; PO file's "Cancelled quantity" is read-only info, never used for netting.
2. ~~Who can upload~~ **RESOLVED:** **Admin only** for now; open to the team (their own
   parts) once fully onboarded. (5 roles exist: Admin / Finance / Sales / Procurement / Warehouse.)
3. Exact final **file templates + fixed filenames/formats** — to be locked at the end.
4. Confirm `line_items` / packing-list / cancellation DB column names against the Laravel
   migrations (done in the Codespace at build time).

---

## I. Decisions locked (don't re-litigate)

- Logo = **Concept B** (teal `#0d9488` linked-node "operon" mark).
- Build target = the **Laravel app** (`backend/`) in the GitHub repo, via Codespaces.
- Working rule: clarify fully first; **build only on the word `EXECUTE`**, in one pass.

---

## J. Upload tab — UX + validation (locked)

- **Admin only** for now (see H.2).
- **User picks the exact file type from a dropdown FIRST, then uploads** — no auto-guessing.
  The dashboard runs that type's logic immediately.
- **Validation guardrail:** each type has a known fingerprint (sheet name / expected
  columns). If the uploaded file doesn't match the chosen type, **reject it with a clear
  message** before importing anything. (This replaces the legacy "auto-detect then confirm"
  flow with "choose then validate" — simpler and safer.)
- Dropdown options (Amazon focus for now), each a single clean choice:
  - Amazon — Purchase Order  → `.xls`, sheet "Line Items", has ASIN + Ship-to columns
  - Amazon — **Interim** Packing List  → *(fingerprint TBD from file 4)*
  - Amazon — **Final** Packing List  → *(same format, final stage)*
  - Amazon — Cancellations  → confirmed template (see §G)
  - Amazon — Sell-out report → metadata row 1 + header (ASIN, Shipped Revenue/COGS/Units, Returns)
  - Amazon — DFS orders → header (order id, Invoice#, Invoice date, ASIN, SKU, QTY, Invoice amount)
  - Noon — Purchase Order → reads "Packing List" tab + PO-number metadata tab
  - Noon — Interim Picking List → reads "Picking List" tab
  - Noon — Final Picking List → reads "Picking List" tab
  - *(later)* Master SKU mapping, Noon sell-out report
- Interim vs Final are **separate dropdown entries** (no extra sub-step).
- **Upload-freshness reminder:** each file type can carry an expected cadence; if the latest
  upload is older than it, show a **dashboard nudge banner** ("DFS not uploaded in 9 days —
  upload the latest"). Generic mechanism, enabled per file type (DFS = weekly). Event-driven
  files (POs = Mon/Wed + ad-hoc) left off by default to avoid noise. Phase 1 = in-dashboard
  banner; email/push nudges later.

---

## K. Files 4 & 5 — Packing lists (Interim + Final) — "Simple List" tab ONLY

**Read ONLY the `Simple List` tab.** Ignore the `Short Titles` and `Packing List` tabs
entirely (they exist but only cause confusion). Both interim and final share this layout.

- **Delivery identity = ASN.** `D1` reads `Shipment Name: …` containing an **11-digit ASN**
  (Advance Shipment Notice number, unique per delivery) + an internal ref `Aug-0X`, in
  **either order** (`Aug-01-22161389743` vs `22161964743-Aug-02`). Parse the number out
  regardless of position. **The ASN is the delivery key** — it links interim ↔ final (same
  ASN = same delivery) and groups the lines into one ISA/delivery.
  - Note the three look-alike acronyms: **ASIN** = product (join key), **ISA** = appointment
    ID on the letter (ignored), **ASN** = this shipment number (the key we use). `Aug-0X`
    is an internal label, **not a date**.
- **`D2` = `Shipment Date: …`** → **planned/provisional only** (Amazon reschedules per FC
  capacity). Store it, mark it "may change", wire **no** hard logic to it. Authoritative
  shipped date TBD (likely from the final packing list — confirm at file 5).
- **Header = row 4:** `PO · ASIN · Model Number(barcode) · Title(short) · Qty · Carton(range)
  · Unit Cost`. **Column G (Unit Cost) is hidden** (feeds invoice value `G2`; mainly for the
  final → accounts). Data starts **row 5**.
- **Skip `Carton total` rows** (Title cell literally = "Carton total") — they're per-carton
  subtotals for the packer and would double-count. Detected cleanly by that title text.
  - Mixed carton = several rows (green-highlighted) **sharing one carton number**; their
    individual item quantities ARE counted, only the "Carton total" subtotal row is skipped.
- **Booked/Shipped per PO-line = Σ Qty of that PO+ASIN across item rows** (same ASIN split
  across cartons just adds up). **Interim → "Booked/Scheduled"; Final → "Dispatched/Shipped".**
- **FC** is not in the packing list (excluded on purpose — packing team doesn't need it).
  Derive the delivery's FC from its POs; all POs in one packing list should share one FC
  (sanity check).
- **Unmatched lines** (PO+ASIN not yet ingested): **accept & store anyway — no error, no
  drop.** Expected during rollout (new POs vs older booked deliveries). **Auto-reconcile** to
  the PO-line when that PO is ingested later.

- **Upload as-is — no manual cleanup.** The Simple List cells are formulas referencing the
  hidden `Packing List` tab; the reader takes each cell's **cached calculated value** (verified
  working). Do NOT ask the user to flatten/paste-values. Parser must be **defensive**: if a
  cached value is ever missing, handle gracefully rather than importing a blank.

**Sample data verified:** DXB6/Aug-01 (ASN 22161389743): 5 POs, 85 item rows, 65 ASINs,
468 units, 11 carton-total rows skipped. DXB3/Aug-02 (ASN 22161964743): 2 POs (774FV9FB,
1L5KQKGM — matching the appointment letter's PO list), 9 items, 641 units.

### Final packing list — specifics (same "Simple List" tab, columns SHIFTED)

- **Map columns by HEADER NAME, not position** (columns shift between interim & final; also
  apply header-name mapping to the PO file). Final layout: `A=PO · B=(invoice prefix) ·
  C=Invoice Number (per PO, "BD-####-PO") · D=ASIN · E=Model Number · F=Title · G=Qty(shipped)
  · H=Carton · I=Unit Cost(now visible) · J/K=Match key/Lookup row (ignore) · L=Line Value`.
  Banner "Shipment Name/Date" in **F1/F2**; "Invoice value" in **I1/I2**.
- **No "Carton total" rows** and **no zero-qty lines** (undelivered items are deleted) —
  required so QuickBooks invoicing doesn't error.
- **Final Qty = authoritative shipped/dispatched.** A final packing list existing for an ASN
  = those units shipped. Shipped(PO,ASIN) = Σ final Qty across all ASNs. This drives fill rate.
- **Invoice number** (col C, one per PO) stored as-is for the accounts/QuickBooks link.
- Links to its interim by the **same ASN**.

---

## L. Revenue & shortfall (Phase 1 core)

- Booked value = Σ interim Qty × unit cost. Shipped/invoiced value = Σ final Qty × unit cost
  (= the final "Invoice value" total).
- **Shortfall = interim − final**, in both units and AED, attributable to specific SKUs
  (reduced or fully deleted in final). Verified: DXB3 14,240.95→13,764.59 (−476.36);
  DXB6 27,994.53→27,399.28 (−595.25).
- Fill rate (final) = Σ final shipped ÷ net-accepted (§E).
- **From the PO file we can also compute PO Confirmation Rate = Accepted ÷ Requested**
  (Amazon benchmark ~80–85%).
- **Dates (RESOLVED):** fill-rate/revenue need no date. Time-based reports **anchor on the
  PO's Order/Expected date** (stable, never changes). Karan will ensure the **packing list
  carries the EXACT delivery date** → use it as the actual fulfilment date.
- **Turnaround / lead time (a defining business KPI):**
  - **Headline = time-to-full-fulfilment** = completion date − PO date (e.g. PO 3 Aug →
    completed 27 Aug = 24 days). Completion = when shipped ≥ net-accepted (or remainder
    cancelled). While still open, show **"X days and counting"**.
  - **Benchmark = 10 days.** Anything over is flagged. (Current reality: 2–3 weeks — this is
    the number the business manages down.)
  - Secondary = **time-to-first-shipment** (responsiveness).
  - **PO view:** searching a PO shows **all linked ISAs** (via packing-list PO+ASN), each
    with its date & units, plus the overall days-to-complete.

---

## M. Phase 2 — reporting layer (AGREED as next phase, not phase 1)

Build on the Phase-1 engine: after each final, push a **shortfall report to Sales / Ops /
Warehouse**, let them **add comments/reasons**, and accumulate **historical SKU fill data**
for supplier negotiation and to pre-empt Amazon discontinuing a low-fill SKU.

**Core design principle (cross-cutting, applies to ALL tabs): self-service.** The team must
build their own reports without depending on Karan or an MIS person. So **every data tab**
carries a rich, consistent filter set: Date range (on PO date) · **Channel (Amazon Retail /
Amazon DFS / Noon, and combinations)** · FC · Brand · Category · Status · PO number ·
ASIN/NIN/barcode/title search — plus **Group-by** (SKU/Brand/Category) and **Export**. Each
screen = a self-serve report builder.
**Bulk-ASIN/NIN filter:** the team can **paste or upload a list of ASINs/NINs** as a filter
input on any tab (powers the committed-deliveries lookup — §R).

**Standing task (Karan's request): keep researching what top retailers track.** Research-backed
candidate KPIs/reports, tagged by whether our current data already supports them:

- **PO Confirmation Rate** (Accepted÷Requested) — ✅ have it (PO file). Amazon target ~80–85%.
- **Fill Rate** (Shipped÷Accepted) — ✅ core.
- **Turnaround / lead time** (delivery date − PO date; first-ship & full-fulfilment) — ✅ have it.
- **OTIF (On-Time In-Full)** — In-Full ✅; On-Time needs a reliable date (§L). Walmart's
  flagship compliance metric; Amazon tracks equivalents.
- **Perfect Order Rate** — composite (on-time + in-full + accurate + no chargeback/docs) — partial.
- **ASN accuracy** (planned interim vs actual final per shipment) — ✅ have it.
- **Chargeback exposure** (shipped-after-cancellation flag) — ✅ from cancellation logic.
- **Sell-through / sell-in vs sell-out** (final packing lists vs sell-out report) — ✅ once sell-out ingested.
- **Customer returns rate** (from sell-out report) — ✅.
- Vendor-scorecard-style rollups per period / brand / category.
- Not yet (need more data): inventory turnover, cash-to-cash, forecast accuracy.

Refs: Walmart Supplier Scorecard (OTIF / SQEP / ASN Accuracy / FMP / CARS); Amazon Vendor
Scorecard (confirmation rate, availability); general SC KPIs (OTIF, perfect order, fill rate).

**Observed live in BD's Amazon Vendor Central (patterns to mirror):**
- Reports catalog: Custom Analytics · Retail Analytics · Brand Analytics · Operational
  Performance · Direct Fulfilment Reports · Concession Hub.
- Homepage KPI tiles — *Operational:* Sourceable-Out-of-Stock %, ASIN Confirmation Rate, Open
  PO Qty, On-Hand Inventory (Sellable), Invoiced Amount; *Business (weekly + trend):* Product
  COGS, Ordered Revenue, Ordered Units, Net PPM. PO buckets: Unconfirmed / Confirmed /
  **Mismatched PO vs ASN qty** / Recently modified.
- **Operational Performance = the scorecard:** **In Full Delivery**, **ASN Accuracy**, **Prep
  Issues** each as a **defect % vs a 5% target** with trend + chargebacks + defect list;
  filter by vendor code / order type / date. "In Full Delivery" consolidates NotFilled +
  Overage + Down-confirmed. (BD's was 62% = "Poor" → Operon's fill engine targets exactly this.)
- **Use Amazon's own targets as our green/amber/red thresholds** (≤5% defect / ~95% in-full).
  Mirror the two-tile-row overview + drill-down pattern.

---

## N. Phase 3 — intelligence layer (future; needs ~3 months of accumulated data)

Design the Phase-1 data model so these plug in later with **no re-architecting**:

- **Forecasting module:** use SKU-level movement + sell-in vs sell-out to build inventory
  ahead of demand, driving turnaround down toward the 10-day benchmark.
- **PO prioritisation engine:** when new POs arrive (e.g. "10 POs across 5 FCs today"),
  proactively recommend which to fulfil first based on historical fast-moving ASINs
  ("bank the momentum").
- **DFS replenishment recommendation:** recommend the DFS holding-order qty **net of
  already-booked PO units** — automatically preventing the DFS↔PO overstock trap (§R).
- **Supplier-PO uploads + supplier-wise performance:** procurement consolidates POs (across
  ISAs) into one supplier order; pull that from the internal tool (link by PO/ISA ref) →
  supplier scorecard (cartons ordered vs delivered per supplier) + **exact per-PO cost** →
  unlocks the weighted-average cost decision (§S).

These depend on accumulated history, so they come after the engine (Phase 1) and reporting
(Phase 2) have been running and collecting data.

---

## O. Roles & permissions (from Operon_Permission_Matrix.xlsx — TENTATIVE, first draft)

Repo source of truth: `backend/database/seeders/RolesAndPermissionsSeeder.php` — align it to the
matrix. 5 roles: **Admin / Finance / Sales / Procurement / Warehouse**. Granular permissions
(`view-*` per tab, `manage-*`, `upload-*` per file type, cost/price/margin visibility).

- **Money visibility:** `view-margin` = Admin+Finance · `view-sku-cost` (buy price) =
  Admin+Finance+Procurement · `view-sku-price` (sell price) = Admin+Finance+Sales ·
  Warehouse = none.
- **Uploads (matrix target):** `upload-po` = Admin+Procurement · `upload-packing-list` =
  Admin+Warehouse · `upload-cancelled-items` / `upload-master-sku` = Admin only ·
  `manage-users` = Admin only.
- **LAUNCH RECONCILIATION:** Karan's current-phase rule = **ALL uploads Admin-only**. Build the
  full matrix but at launch grant `upload-*` to Admin only; switch Procurement/Warehouse upload
  rights on when the team is onboarded.
- Planned-feature perms already seeded: `manage-delivery-batches`, `manage-email-assistant`
  (map to Phase 2/3 features).

---

## P. File — Amazon Sell-out report (sell-through) — ingest now, analytics in Phase 2

- File `Sales_ASIN_Sourcing_Retail_*_<from>_<to>.xlsx`, one sheet. **Row 1 = metadata**
  (Viewing Range, Report Updated, Currency=AED, View By=ASIN). **Header row 2:** `ASIN ·
  Product Title · Brand · Shipped Revenue · Shipped COGS · Shipped Units · Customer Returns`.
  Data row 3+. ~2-day lag; default 30-day window (adjustable). Links by **ASIN**. Some rows
  have only returns (blank sales) — handle blanks.
- **Meaning:** this is **sell-out** (Amazon → end customer). Our **sell-in** = final packing
  lists (us → Amazon).
- **Sell-in vs Sell-out (Phase 2):** ratio = sell-out ÷ sell-in over a period, per
  ASIN/brand/category. Low ratio = inventory piling at Amazon = risk (Amazon throttles future
  POs; cash tied on ~60-day terms). **Auto-flag** below benchmark (Karan's June example: 1.5M
  in vs 0.75M out = 50% → flag). Track **Customer Returns** too. Claude to propose industry
  sell-through benchmarks.

---

## Q. Noon (second vendor) — same engine, different key

- Marketplace = **noon**. **Join key = NIN / ZSKU** (e.g. `Z8C550…Z-1`). GTIN/Barcode + Seller
  SKU = display/search only.
- **One file per PO** (PO# from the PO-number-named tab metadata / filename). Tabs: `Short
  Titles` (ignore) · `<PO#>` (metadata: P.O No, Date, Approval Date, Est. Delivery, Ship To→FC,
  Currency) · `Packing List` (PO detail) · `Picking List` (interim/final).
- **Tab mapping:** Noon **PO** upload → read `Packing List` tab (+ metadata tab). Noon
  **interim/final** → read `Picking List` tab (stage chosen in dropdown).
- **Quantities:** ordered = **UOM Qty** (Packing List); shipped = **Qty** (Picking List). No
  "accept less than ordered" step → **Confirmation Rate is Amazon-only**. **Ignore `OG qty`**
  (Karan's internal ref). Fully-undelivered SKUs deleted from final (like Amazon).
- **No cancellation file** for Noon. Invoice number in final (col C, `BD-####-…`). FC from
  Ship To text (`AUH01G`) / filename. Header-name column mapping (same principle).
- **Delivery model (differs from Amazon):** deliveries booked manually by email → Noon issues
  an **ASN per PO**. **Usually 1 PO = 1 delivery/ASN** (multiple trucks allowed). **~10% split
  into 2 ASNs** (both unique, both linked to the one PO). Engine **sums finals per PO+NIN
  across deliveries** → splits handled automatically. ASN is email-only → optional (manual
  entry only if per-ASN drill-down wanted; not in the file).
- **Turnaround:** PO date = Date/Approval Date. **Delivery date is NOT reliably in the Noon
  file** (only an "Estimated Delivery Date" in metadata) → capture via a **"Delivery date"
  field in the Noon upload confirm step, pre-filled with the Estimated Delivery Date, editable**
  to the Noon-email-confirmed date. Final's date = actual completion (drives turnaround);
  interim date optional. **Optional ASN field** at upload too (for per-delivery drill-down /
  the ~10% two-ASN split). Editable later on the PO view if rescheduled.
- Everything downstream (fill rate, shortfall, revenue, margin, turnaround, analytics)
  identical to Amazon. Noon **sell-out report** to come later (same sell-in/sell-out logic).

---

## R. Amazon DFS (Direct Fulfilment) — THIRD channel, NOT the PO engine

- **What it is:** Amazon routes **actual end-customer orders** to us to fulfil from our own
  held inventory (because their FC is full or out of our stock). We invoice Amazon per order.
- File `DFS <Month Year>.xlsx`, one sheet: `order id · Invoice number (BD-DFS-####) · Invoice
  date · ASIN · SKU · Item Description · QTY · Invoice amount`. **Outbound only — no returns.**
- **No PO, no fill rate** — it's a direct **sales/revenue feed by ASIN over time**.
- **Channel dimension:** Amazon Retail / **Amazon DFS** / Noon (in the §M channel selector).
- **Upload frequency: weekly** (confirmed; matches the team's ordering cycle; accumulates)
  → covered by the upload-freshness reminder (§J).
- **KEY use — the DFS↔PO overstock trap:** team orders DFS holding inventory from sales
  (sell-out + DFS) but **misses that POs are already booked to ship the same SKUs next week** →
  overstock. **Fix = "Upcoming committed deliveries" lookup:** enter a **date range** and/or
  **paste/upload a list of ASINs** → **units per ASIN already booked** in upcoming (interim)
  deliveries. Team nets DFS orders against this. (Uses the §M bulk-ASIN filter.)
- **Combined per-ASIN demand view (Phase 2):** per period, per ASIN → **sell-out units + DFS
  units + upcoming-booked units** side by side, so ordering decisions see the full picture.

---

## S. Master Sheet (product catalog + unit economics) — USE `Master_Products_Sheet (1).xlsx`

- **Adopt the existing sheet** (2,714 rows, 32 cols) — far richer than a fresh template.
- **`Company Product Code` (BD#####) = the canonical product key** (confirmed stable per
  physical product). Unifies ASIN / NIN / DFS across channels — **762 products already
  cross-linked**. Do NOT rely on barcode for cross-platform linking.
- **Identifier per channel (verified):** Amazon VC/DFS → ASIN; Noon (Retail/Bulk/SC) → NIN;
  Tradeling → its own codes. Column `Customer Product Code` holds the channel-native ID;
  `Customer Code`/`Name` identifies the channel.
- **Money columns power TRUE net margin** (not just gross): Invoice Cost Price / Product Cost,
  RSP (sell), Fulfilment/Referral/Storage/Cat/Other fees, Net Receivable, Platform Fees %,
  Marketing, OPEX, Packaging, COGS, Profit, Profit %, Margin %.
- **Cost rule (interim):** a product has **multiple suppliers** → use the **LATEST price**.
  **⚠ PENDING DECISION** — flips to a **weighted average** once Supplier-PO uploads exist
  (Phase 3). Flag this in-app.
- **Cleanup:** trim trailing whitespace/newlines in ASINs; ~2 Noon rows wrongly hold an ASIN;
  de-dupe multi-supplier rows to one cost per product (latest).
- **In-scope channels (Phase 1):** **Amazon VC + Amazon DFS + Noon Retail** only. Tradeling /
  Noon Bulk / Noon SC stay in the catalog but dormant until switched on.

### Editable master — Path A CONFIRMED (Excel-*like* in-app grid, not literal Excel)
- **Path A (chosen):** a spreadsheet-style **editable master screen inside OperON** — rows/
  columns, click-to-edit cells, inline add/delete, saves instantly, **no download-edit-upload**.
  Looks/feels like Excel but is OperON's own grid (NOT the Microsoft Excel app embedded). The
  P&L formulas become the **app's own calc logic** (applied to every PO/SKU everywhere). App DB
  = single source of truth. **Admin-only + PIN/password.** Keep **Excel bulk-upload** for mass loads.
- **Designed to stay switchable:** because data lives in the DB (not a file), we can export to
  Excel/Sheets anytime and later ADD an embedded-sheet (Path B) view syncing to the same DB —
  an add-on, not a rebuild.
- Path B (embed a live Google Sheet as source of truth): keeps literal sheet/formulas but = two
  systems to sync + access-control lives in Google, not the app. Deferred; not the default.

### Profitability views (money = ADMIN ONLY, behind PIN/password)
- **PO-level net P&L:** revenue − (product cost + fees + marketing + OPEX + packaging) → net
  profit + margin % per PO (e.g. "billed 10,000 → net 1,000 = 10%").
- **SKU-level net margin:** per SKU, is it profitable? Channel selector Amazon / Noon / **Both**.
- **"Both" = REVENUE-WEIGHTED average** for margin % (unit-weighted where combining unit
  costs). Never a simple average.

---

## T. File & template registry (LOCKED)

Upload gate = **dropdown type + content validation** → filenames are informational, not enforced.

| Upload type | Format / sheet | Filename | Source |
|---|---|---|---|
| Amazon PO (bulk) | `.xls`, "Line Items" | `POItemExport_<date>.xls` | Amazon — as-is |
| Amazon PO (single) | `.xlsx`, "Purchase Order" | `PurchaseOrder.xlsx` | Amazon — as-is |
| Amazon Interim packing | `.xlsx`, **"Simple List"** | `PACKING LIST_<ASN>-<ref> - Interim.xlsx` | your tool — as-is |
| Amazon Final packing | `.xlsx`, **"Simple List"** | `PACKING LIST_<ASN>-<ref> - Final.xlsx` | your tool — as-is |
| **Amazon Cancellations (TEMPLATE)** | `.xlsx`, "Cancellations" | `Amazon_Cancellations_<YYYY-MM-DD>.xlsx` | **you build — fixed cols** |
| Amazon Sell-out | `.xlsx` | `Sales_ASIN_Sourcing_Retail_…xlsx` | Amazon — as-is |
| Amazon DFS | `.xlsx` | `DFS_<YYYY-MM>.xlsx` | you export |
| Noon PO / Interim / Final | `.xlsx` (tabs) | Noon tool's name | your tool — as-is; stage via dropdown |
| Master Sheet | `.xlsx` bulk + **in-app grid** | `Master_Products_Sheet.xlsx` | you — admin + PIN |

- The **only** user-built template is **Amazon Cancellations** — fixed columns: `PO Number ·
  ASIN · External ID · Description · Quantity Confirmed · Quantity Cancelled` (blank template
  `Amazon_Cancellations_TEMPLATE.xlsx` provided; PO/ASIN/External ID stored as text).
- The **Master Sheet is real data**, not a template (bulk `.xlsx` load + in-app editable grid).

---

## U. Build plan — Phase 1 (in the Codespace), in order

- **M0 — Foundation:** align `RolesAndPermissionsSeeder` to §O matrix; auth; admin + PIN scaffolding.
- **M1 — Data model/migrations:** products (master, Company-Product-Code key), po_lines, packing_lines
  (interim/final), cancellations, deliveries (ASN + Noon delivery date), sellout, dfs_orders, upload audit.
- **M2 — Upload framework:** dropdown type + content validation + freshness reminders + audit log;
  header-name-mapping parser core.
- **M3 — Amazon ingest:** PO (bulk `.xls` + single) + interim/final packing (Simple List) +
  cancellations. **← the original "Amazon PO slice". CHECKPOINT.**
- **M4 — Reconciliation engine:** booked/shipped/not-booked/cancelled, fill rate, shortfall,
  deliver-anyway flag, turnaround.
- **M5 — Core screens:** Overview KPIs, PO Lookup (multi-ISA), Fulfilment, Pending, Shipments,
  Committed-deliveries lookup — all with self-serve filters + channel selector + bulk-ASIN + export.
- **M6 — Master grid** (admin+PIN) + **net-margin engine** (true P&L).
- **M7 — Money views:** PO- & SKU-level margin (admin-only + PIN). **CHECKPOINT.**
- **M8 — Noon channel** ingest (NIN) + delivery-date entry.
- **M9 — DFS + Sell-out** feeds; sell-in vs sell-out; committed-deliveries integration.
- **M10 — Users, server-side permission enforcement, final verification** vs the real tested files.
- **Execution mechanism:** Option B (background agent → pull request) preferred; else Option A
  (local clone + push). To be picked at EXECUTE.
