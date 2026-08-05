# OperON — Project Documentation

**Living document.** The agreed spec is [`03_LOGIC_BLUEPRINT.md`](03_LOGIC_BLUEPRINT.md).
This file records how the spec has actually been built: what exists, how to run it,
what was decided during the build, and what is still open.

Last updated: **M5 complete — the core screens are live.**

---

## 1. What OperON is

An internal dashboard for a B2B e-commerce distribution business. It ingests the
spreadsheets the team already handles (Amazon and Noon purchase orders, packing
lists, cancellations, sell-out and DFS reports, plus the master product sheet) and
answers:

- **Fill rate** — of what the marketplace accepted, how much did we actually ship?
- **Shortfall** — what did we plan to ship but didn't, in units and AED, per SKU?
- **Turnaround** — how many days from PO to full fulfilment, against a 10-day benchmark?
- **Profitability** — true net margin per PO and per SKU (Admin only, PIN protected).

### The keys that hold it together

| Key | What it identifies | Notes |
|---|---|---|
| **ASIN** | An Amazon product | The join key. Always used with the PO number. |
| **NIN / ZSKU** | A Noon product | Noon's equivalent of ASIN. |
| **PO number** | A purchase order | `PO + ASIN` = one order line. |
| **ASN** | One physical delivery | 11-digit number in the packing list. Links interim ↔ final. |
| **Company Product Code** (`BD#####`) | One physical product across all channels | The master catalog key. |
| ~~Barcode~~ | — | **Never a join key.** Leading zeros get lost. Display/search only. |

Three look-alike acronyms, kept straight: **ASIN** = product · **ASN** = delivery ·
**ISA** = Amazon's appointment ID (deliberately not ingested — the packing list carries
everything we need).

---

## 2. Running it locally (Codespaces)

```bash
cd backend
bash tools/install-php-extensions.sh   # once per Codespace — see below
composer install
npm install
php artisan migrate --seed     # creates tables, roles and demo users
npm run dev                    # in one terminal
php artisan serve              # in another
```

> **PHP extensions.** The Codespace image ships PHP without `ext-zip`, which is required
> to open `.xlsx` files (an `.xlsx` is a zip archive). `tools/install-php-extensions.sh`
> builds and enables it; run it once after creating or rebuilding a Codespace.
> `ext-gd` is deliberately *not* installed — PhpSpreadsheet only needs it for embedded
> images, which we never read, so `composer.json` declares it satisfied via
> `config.platform`.

Demo logins (development only), password `password` for all:

`admin@demo.local` · `finance@demo.local` · `sales@demo.local` ·
`procurement@demo.local` · `warehouse@demo.local`

Run the tests with `php artisan test`.

> **Note on Xdebug:** this Codespace has Xdebug enabled and prints a harmless
> "Could not connect to debugging client" warning on every `php` command.
> Prefix commands with `XDEBUG_MODE=off` to silence it.

---

## 3. Build status

| Milestone | What it delivers | Status |
|---|---|---|
| **M0** | Permission matrix aligned to §O, launch upload lockdown, money PIN gate | ✅ Done |
| **M1** | Full data model / migrations | ✅ Done |
| **M2** | Upload framework + parser core | ✅ Done |
| **M3** | Amazon ingest — **checkpoint, verified on real files** | ✅ Done |
| **M4** | Reconciliation engine — turnaround, deliver-anyway workflow, PO completion | ✅ Done |
| **M5** | Core screens with self-serve filters, group-by and export | ✅ Done |
| M6 | Master grid + net-margin engine | ⬜ |
| M7 | Money views — **checkpoint** | ⬜ |
| M8 | Noon channel | ⬜ |
| M9 | DFS + sell-out feeds | ⬜ |
| M10 | Users, server-side enforcement, final verification | ⬜ |

Work happens on the **`phase-1-build`** branch. `main` is never committed to directly.

---

## 4. M0 — Foundation (done)

### 4.1 Roles and permissions

Defined in `backend/database/seeders/RolesAndPermissionsSeeder.php`, which is the
repo's source of truth for §O. 29 permissions across 5 roles, grouped as
screens · actions · money · uploads.

**Money visibility** (as specified in §O):

| Permission | Admin | Finance | Sales | Procurement | Warehouse |
|---|:--:|:--:|:--:|:--:|:--:|
| `view-margin` (net P&L) | ✅ | ✅ | — | — | — |
| `view-sku-cost` (buy price) | ✅ | ✅ | — | ✅ | — |
| `view-sku-price` (sell price) | ✅ | ✅ | ✅ | — | — |

**Uploads — the launch rule.** The full §O matrix (Procurement uploads POs, Warehouse
uploads packing lists) is written into the seeder, but a launch override strips every
`upload-*` permission from every non-Admin role. This implements Karan's current-phase
rule: **all uploads are Admin-only**.

To hand upload rights to the team later:

```bash
# in backend/.env
OPERON_UPLOADS_ADMIN_ONLY=false
```
```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

Nothing else changes — the matrix is already built and tested for both states.

### 4.2 The money PIN

Blueprint §S requires money screens to be Admin-only *and* behind a PIN. That is two
independent layers, both enforced on the server:

1. **Role permission** (`view-margin` etc.) — decides who may ever see the screen.
2. **Session PIN** — `App\Http\Middleware\EnsureMoneyPinVerified`, alias `money.pin`.

A route uses both:

```php
Route::get('/money', ...)->middleware(['permission:view-margin', 'money.pin']);
```

The PIN is entered once, stays valid for `OPERON_MONEY_PIN_TIMEOUT` minutes (default 30,
sliding), can be re-locked manually from the dashboard, and rate-limits wrong attempts.
A correct PIN never substitutes for a missing permission — that is covered by a test.

**Change `OPERON_MONEY_PIN` in `.env` before any real data goes in.** It ships as `1234`.

### 4.3 Configuration

New file `backend/config/operon.php` holds the switchable business rules: the upload
lockdown, PIN settings, the §L/§M benchmarks (10-day turnaround, 95% fill rate, ≤5%
defect, ~80% confirmation rate) and the Phase-1 channel list.

### 4.4 Tests

`PermissionMatrixTest` and `MoneyPinTest` lock down the rules that are easiest to break
by accident. Full suite: 35 passing.

---

## 5. M1 — Data model (done)

### 5.1 The tables

Ten tables, replacing the six Phase-0 sketch tables (which were empty and have been
dropped — their migration files are deleted, so a fresh install never recreates them).

| Table | One row = | Key |
|---|---|---|
| `products` | One physical product | `company_product_code` (BD#####) |
| `product_identifiers` | An ASIN or NIN, pointing at a product | `(marketplace, sku_id)` |
| `purchase_orders` | One PO | `(marketplace, po_number)` |
| `po_lines` | One PO × SKU line — **the spine** | `(marketplace, po_number, sku_id)` |
| `deliveries` | One delivery | `(marketplace, delivery_key)` — the ASN |
| `shipment_lines` | One packing/picking list row | not unique — cartons split |
| `cancellations` | One cancelled PO × SKU | `(marketplace, po_number, sku_id)` |
| `sellout_rows` | One ASIN's sell-out for a period | `(marketplace, sku_id, period)` |
| `dfs_orders` | One DFS order line | `(order_id, sku_id)` |
| `source_files` | One upload | — |

Every fact table stores its raw `(marketplace, sku_id)` exactly as the file gave it,
plus a **nullable** `product_id`. That nullability is the design decision that makes §K
work: a packing list for a PO we haven't loaded yet, or a SKU the catalog doesn't know,
is stored and flagged — never dropped — and links up later.

### 5.2 How the blueprint's rules are encoded

- **`po_lines.qty_cancelled_po_file`** exists but is documented in the migration as
  read-only. Netting comes only from `cancellations` (§G).
- **`cancellations.resolution`** carries the deliver-anyway decision, with
  `qty_honoured` and `qty_delivered_anyway` split out so a partly-shipped line nets
  correctly and still raises the chargeback flag.
- **`deliveries.planned_date`** (the banner "Shipment Date") is separate from
  `delivered_on` (the real date), because the banner date is provisional and no hard
  logic may hang off it (§K).
- **`deliveries.delivery_key`** is the ASN for Amazon and a generated stand-in for Noon,
  which has no ASN in the file (§Q). One table serves both.
- **Cached columns** (`qty_booked`, `qty_shipped`, `fill_rate_pct`, `line_state`,
  turnaround dates, delivery totals) are written by the M4 engine. The *definitions*
  live as `compute*()` methods on the models, so the engine and the screens cannot drift.

### 5.3 Type-safe enums

`Marketplace` (identity: amazon/noon) · `Channel` (reporting: amazon_retail /
amazon_dfs / noon_retail) · `Stage` (interim/final) · `UploadType` (the §J dropdown,
carrying each type's permission and freshness cadence) · `CancellationResolution` ·
`SourceFileStatus`.

Marketplace and Channel are deliberately different things: Amazon Retail and Amazon DFS
share one identity namespace (both use ASINs) but are two channels in every report.

### 5.4 Tests

`DataModelTest` runs the blueprint's own verified figures through the schema: the §E
worked example (2,000 accepted, four finals totalling 1,980 → 99%), the §L DXB3
shortfall (14,240.95 → 13,764.59 = −476.36), carton splitting, unmatched-line
reconciliation, the deliver-anyway decision trigger, and Amazon/Noon key differences.
Full suite: **48 passing**.

---

## 6. M2 — Upload framework (done)

### 6.1 What happens when you upload a file

1. **You choose the file type from a dropdown first.** Nothing is auto-detected. The
   dropdown only lists types your role may upload — at launch, Admin only.
2. The screen shows **what will be checked** for that type before you pick a file:
   expected format, which tab is read, which columns are required.
3. The file is **saved and logged first**, so even a rejected upload leaves a trace of
   who tried what and why it bounced.
4. It is **checked against that type's fingerprint**: extension → does it open → is the
   right tab there → is there a header row with every required column → is there data.
5. **Pass** → status *Validated*, with the tab, header row and every column found
   recorded. **Fail** → status *Rejected*, with a message written for you, not for a
   developer. Either way, **nothing reaches the data tables unless it passed**.

A rejection reads like:

> You chose "Amazon — Purchase Order (bulk export)", which reads the "Line Items" tab,
> but this file has no such tab. It contains: "Short Titles", "Packing List",
> "Simple List". Either the wrong file type was selected, or this is not the file you
> meant to upload.

### 6.2 The parser core

- **`Workbook` / `Sheet` / `CellValue`** — reads `.xls` *and* `.xlsx` in data-only mode.
  Formula cells yield their **cached calculated value**, which is what the packing lists
  need (§K), with a fallback if a cache is ever missing. `CellValue::asText()` keeps
  identifiers intact — Excel stores `634562947130` as a float, and a naive cast would
  produce `6.3456294713E+11`.
- **`HeaderMap`** — the §K "map by header name, not position" rule. Matching ignores
  case, punctuation and spacing, tries a list of aliases per field, then falls back to a
  contains-match. This is what lets one parser read both the interim and final packing
  lists despite every column shifting.
- **`FileTypeRegistry`** — the §T registry as code: per type, the allowed extensions,
  acceptable tab names, required and optional columns with their aliases, and a
  plain-English note explaining the file. **These aliases come from the blueprint's
  descriptions and are confirmed against the real files at M3.**
- **Header row is found, not assumed.** The definition's row number is a hint; the
  validator scores the first dozen rows and picks the best match. A one-row shift in an
  Amazon export therefore does not break every upload. Covered by a test.

### 6.3 Also delivered

- **Upload audit log** with content hashing, so re-uploading an identical file is
  detectable.
- **Freshness nudge** (§J) on the dashboard and upload tab: types with an expected
  cadence (DFS and sell-out, weekly) warn when stale. POs are event-driven and
  deliberately never nag.
- **The blank cancellations template** — the only file the team builds themselves.
  Downloadable from the upload tab, with the six fixed columns, and PO/ASIN/External ID
  forced to text so a paste from an email cannot strip a leading zero. It round-trips:
  a test generates it and feeds it back through its own validator.

### 6.4 Tests

`UploadValidationTest` (18) covers the fingerprint logic including a genuine `.xls`
round-trip, the shifted header row, and identifier/number/date parsing.
`UploadFlowTest` (15) covers the HTTP flow: who may reach the tab, wrong-type rejection
with nothing written, the template download, and the freshness banner.
Full suite: **81 passing**.

---

## 7. M3 — Amazon ingest (done, verified on real files)

### 7.1 The four parsers

| Parser | Reads | Notes |
|---|---|---|
| `AmazonPoImporter` | Bulk `.xls` export **and** the single-PO `.xlsx` | One class; the differing column names are handled by header-name matching |
| `AmazonPackingListImporter` | Interim **and** final packing lists | One class; stage comes from the dropdown |
| `AmazonCancellationImporter` | The cancellations sheet | The only thing that nets |
| `Reconciler` | — | Links out-of-order rows, computes the cached figures |

### 7.2 Verifying against the real files

```bash
php artisan operon:verify-samples --dir=/workspaces/Operational-Dashboard --fresh
```

This imports through the **real upload pipeline** — same validation, same importers,
same audit log as the web form — and prints actual-vs-expected for every figure the
blueprint validated by hand. The sample files are git-ignored; the command is committed
and simply does nothing if they are absent.

**Result — every check passed:**

| Check | Got | Expected |
|---|---|---|
| Bulk export: lines / POs / FCs | 126 / 10 / 7 | 126 / 10 / 7 |
| Multi-delivery PO: ASINs | 87 | 87 |
| Units accepted | 14,740 | 14,740 |
| Units shipped across 8 deliveries | 14,117 | 14,117 |
| **Fill rate** | **95.77%** | **95.77%** |
| Packing lines with no matching PO ASIN | 0 | 0 |
| Over-shipped ASINs | 0 | 0 |
| Shortfall: ASINs / units | 3 / 623 | 3 / 623 |
| ASN 22161389743 interim: units / rows / POs / carton-totals skipped | 468 / 85 / 5 / 11 | 468 / 85 / 5 / 11 |
| ASN 22161964743 interim: units / rows / POs | 641 / 9 / 2 | 641 / 9 / 2 |
| Shortfall values (both deliveries) | 595.25 / 476.36 AED | 595.25 / 476.36 AED |

### 7.3 What the real files taught us

Things confirmed or corrected against actual data, not assumed:

- **Formula cells work as designed.** The Simple List is 100% formulas referencing the
  hidden tab. Reading each cell's cached value returns the right numbers, so files
  upload exactly as the tool produces them — no flattening, no paste-values.
- **The final layout really does shift.** Interim: `A=PO · B=ASIN · C=Model · D=Title ·
  E=Qty · F=Carton · G=Unit Cost`, banner in D1/D2. Final: everything moves right for
  `C=Invoice Number`, banner moves to F1/F2, invoice total to I1/I2. Matching by header
  name handles both with one parser; the banner is found by its label, not its cell.
- **The single-PO file's PO number is genuinely absent** — no column, and the filename
  is literally `PurchaseOrder.xlsx`. The upload form now has a PO-number box that
  appears for that type; the filename is used when it does contain one.
- **The cancellations mock's tab is `Sheet1`,** not "Cancellations". Both are accepted.
- **Carton-total rows behave exactly as described** — 11 on the Aug-01 list, and
  skipping them is the difference between 468 units and double-counting.
- **Some deliveries have no stage in the filename** (`PACKING LIST_22183953643-AUG-25.xlsx`).
  This is fine and by design: the stage comes from the dropdown (§J), never the filename.

### 7.4 Two bugs the tests caught that the samples did not

Worth recording, because both would have surfaced later in production:

1. **A `static` cache in the PO importer leaked between imports** in the same process.
   The verification run never noticed (each import was effectively first), but a second
   import in one request or queue worker would have attached lines to a stale PO.
   Now an instance property, reset per import.
2. **A packing list with no "Invoice value" banner crashed the import.** Every real
   sample happens to have one. Now it falls back to totalling the line values.

### 7.5 Tests

`AmazonImportTest` (20 tests) covers what the real samples cannot: cancellation netting
(every cancellation in the sample set names a PO that was not supplied), the
deliver-anyway decision trigger, re-upload behaviour, and the reconcile-later path.
Full suite: **102 passing**.

---

## 8. M4 — Reconciliation engine (done)

M3 could already answer "how much was booked and shipped". M4 answers the two questions
the business actually manages: **how long did it take**, and **what do we do about a
cancellation for units that have already left**.

### 8.1 Where the rules live

Nothing new is typed in — every figure is derived and recomputed from the imported files.
Three classes, with a deliberate split:

| Where | What it owns |
|---|---|
| `Reconciler` | **When** to recompute, and in what order |
| Model `compute*()` methods | **What** each number means (`PurchaseOrder::computeCompletedOn()`, …) |
| `CancellationDecider` | The §G cancellation rules, on their own |

The order inside a recompute is the part that matters, and it is deliberate:

1. booked and shipped, straight from the packing lines;
2. **re-judge any cancellation nobody has answered yet**, against those fresh figures;
3. net accepted, not-booked, fill rate, line state, chargeback flag;
4. the PO's turnaround and completion, which depend on all of the above.

Step 2 is new, and the reason it exists is in §8.4.

### 8.2 Turnaround (§L)

- **Headline = completion date − PO date**, against the 10-day benchmark. While a PO is
  open it reads **"X days and counting"**, and it is flagged as breaching the benchmark
  whether or not it has landed — a PO 25 days open is the problem, not just a late one
  that finally arrived.
- **Secondary = time-to-first-shipment**, the responsiveness measure.
- **Which date counts.** The date comes from the delivery, and only from a *final*
  packing list — being booked into a delivery is not having shipped. The interim
  banner's "Shipment Date" is never used for anything, because Amazon reschedules it
  (§K). If a final carries no date at all, the day it was uploaded stands in and the
  delivery says so (`fulfilmentDateIsInferred()`), so a screen can mark it rather than
  quietly presenting a guess as fact.

### 8.3 PO completion

Complete when **every line has shipped at least its net accepted quantity**. Because net
accepted already has honoured cancellations taken off it, that one rule also covers §L's
"or the remainder is cancelled" without a second code path.

`completed_on` is the later of the last shipment and the cancellation that closed the
gap. A PO that shipped 800 of 1,000 on the 5th and had the other 200 cancelled on the
20th was **not** complete until the 20th, and that is what it reports.

A cancellation still waiting for an answer nets nothing, so it correctly holds the PO
open until somebody answers — the PO does not quietly close on a decision nobody made.

### 8.4 The bug M4 found in M3

The cancellation rules used to live inside the importer, so they ran **once**, at upload
time, and never again. A cancellation uploaded before its PO was therefore stored,
linked up when the PO arrived — and netted **nothing**, for ever. The upload screen
meanwhile promised "they will net automatically once those POs arrive".

The M3 test only checked that such a row *linked*, not that it *netted*, which is exactly
how it survived. The rules now live in `CancellationDecider` and the Reconciler re-runs
them on every recompute, so the promise is kept. Two rules keep that safe:

- a row **a person has answered** is never re-judged;
- a row that has **already netted** is never un-netted — a packing list arriving later
  cannot hand back units that were legitimately cancelled.

The awkward ordering is covered too: when the packing list *and* the cancellation both
arrive before the PO, the booked units are counted **before** the cancellation is judged,
or it would look free and net itself off units already on a truck.

### 8.5 The deliver-anyway workflow (§G)

A new **Cancellations** screen. Most cancellations never reach it — if the units were
free they netted automatically at upload. What lands here is Amazon cancelling units we
have already booked or shipped, where the system refuses to guess and nets nothing.

Each parked row shows the numbers behind the question — accepted, booked, shipped, still
free, cancelled — and offers two answers:

- **Deliver anyway** — the units stay accepted and count as delivered, so the line can
  still read 100%, and it is flagged for **chargeback exposure**.
- **Pull it** — netted off as normal. But **units already shipped cannot be pulled back**,
  so only the rest is honoured and the shipped remainder is recorded as delivered anyway,
  raising the chargeback flag for exactly those units. The screen says so before you
  click, and the confirmation says what actually happened.

Answering can be what finally completes a PO — pulling back the last 50 units of a
200-unit line that shipped 150 closes it — so the decision recomputes rather than
patching one column.

Seeing the queue needs `view-cancelled-items`; answering needs **`decide-cancellations`,
which is Admin-only**. Everyone else — Finance, Procurement, Warehouse — can watch the
exposure without being able to commit us to a shipment. Both are enforced on the server,
not just hidden in the view. The dashboard carries a nudge while anything is waiting,
because a parked cancellation means real figures are still in limbo.

`decide-cancellations` is its own permission rather than part of `manage-fulfillment`,
so handing this one action to Procurement or Warehouse later is a single line in the
seeder's matrix, with nothing else moving:

```php
// RolesAndPermissionsSeeder::MATRIX, e.g. under 'Procurement'
'decide-cancellations',
```

### 8.6 What the real files taught us

- **The single-PO export has no order date.** It carries only a future "Expected date"
  (a delivery window), which is not the day the PO was raised — using it would produce
  nonsense turnaround. So the upload form now has an optional **PO date** box for that
  file type, beside the PO-number box it already had. A typed date is only ever a
  fallback: a date the file itself carries always wins.
- Nothing is invented when there is no date anywhere. The PO still completes and reports
  its completion date; it simply has no day count, and `verify-samples` says why.
- The multi-delivery PO is **623 units short, and correctly reads as still open** — now
  an asserted check in `verify-samples`, alongside every M3 figure, all still passing.

### 8.7 Tests

`ReconciliationTest` (14) covers turnaround, the benchmark, completion by shipping and
completion by cancellation, the missing-date fallbacks, the re-judging rules in both
directions, and that recomputing twice changes nothing.
`CancellationDecisionTest` (11) covers the workflow end to end: who may see it, who may
answer it, what each answer does to net accepted / fill rate / the chargeback flag, and
what a re-uploaded cancellation file does to a decision already made.
Full suite: **127 passing**.

---

## 9. M5 — Core screens (done)

Six screens, one navigation bar, and one filter set shared by all of them. Everything
they show is the engine's own cached figures, narrowed — no screen recomputes anything,
so a number on a screen and a number in the engine cannot disagree.

### 9.1 The screens

| Screen | Answers | Permission |
|---|---|---|
| **Overview** | How are we doing, against the benchmarks? | `view-overview` |
| **PO lookup** | Everything about one PO, including every ASN its units went into | `view-po-status` |
| **Fulfilment** | Fill rate and shortfall, per line or rolled up | `view-fulfillment` |
| **Pending** | What is accepted but not booked onto any delivery | `view-pending` |
| **Shipments** | Deliveries by ASN, booked against shipped | `view-shipments` |
| **Committed deliveries** | What is already on its way out, per ASIN | `view-committed-deliveries` |

**Overview** follows the pattern the team already reads every day in Amazon Vendor
Central (§M): a row of performance tiles, a row of operational tiles, each against its
own target, each clicking through to the screen that explains it. The thresholds are
Amazon's own — 95% in-full, ~80% confirmation, 10-day turnaround — and they live in
`config/operon.php`, not in the view.

**PO lookup's detail page** is §L's specific requirement: search a PO and see all its
linked ASNs, each with its own date and units, plus the turnaround clock. A delivery
bundles several POs, so the units shown per delivery are *that PO's* units, not the
delivery's total.

**Committed deliveries** is the §R DFS overstock fix. It answers one question per ASIN:
how many units are already booked to ship on a delivery that has not gone yet. Paste the
ASINs from a DFS order into the filter and it tells you what is already covered — and
which of them have nothing committed, so those can be ordered freely. Once a delivery
ships, its units drop off the screen: they are gone, not something to net a new order
against.

### 9.2 The filter set — built once, used everywhere

§M's rule is that every tab carries the same rich filter set so the team never depends on
anyone to build a report. *Same* is the operative word, so there is exactly one
`FilterSet`: date range, channel, FC, brand, category, status, PO number, free-text
search across ASIN/NIN/barcode/title, the bulk identifier list, and group-by. Each screen
says which fields it shows; none of them grows its own.

Three things worth knowing about how it behaves:

- **The date range means the PO's order date**, because §L calls that the stable date to
  anchor time-based reports on. The exception is Shipments, which is about deliveries, so
  there it means the delivery's own date.
- **The bulk list can be thousands of ASINs**, which will not fit in a URL. It is parsed
  once, kept in the session, and only a short key travels in the query string — so paging,
  exporting and sharing the link all keep the list without re-pasting it. Paste a column
  straight out of Excel, a comma-separated line, or upload a text file or a spreadsheet.
- **Brand and category come from the master catalog**, which is not loaded until M6. Until
  then those dropdowns say so rather than sitting there empty, and grouping by brand puts
  everything in one honest "not in the catalog yet" bucket instead of dropping rows.

**Group-by** (SKU / brand / category) and **Export** are on every list screen. The CSV
carries the filters that produced it in its first rows, and writes identifiers as text so
Excel cannot turn an ASIN into a number and lose a leading zero — the same trap the
cancellations template avoids on the way in.

### 9.3 Money on operational screens

**Order value is open to every role, Warehouse included** (revised after M5 — see §14).
Order value is units × unit cost: the size of the order, not what we make on it. The unit
cost on a PO line is the *marketplace's own price* — what Amazon pays us — and it is
already printed on the packing lists the warehouse handles daily.

This is not the margin gate, and the two are deliberately different numbers from different
files. What we **pay** a supplier, and therefore the profit on anything, comes from the
master sheet (§S) and stays **Admin-only behind the PIN**. The split is: *how big is the
order* — everyone; *what do we make on it* — Admin only.

One method carries the rule, `User::canSeeOrderValue()`, and the screens and exports both
ask it. It returns `true` for everyone; the checks stay in place so that narrowing this
again is one line and no screen changes.

### 9.4 Correcting a delivery date

Turnaround is measured to the delivery's date, and that date is not always right — an
Amazon packing list carries the date it was produced, and Noon's file has no reliable
date at all (§Q). So a delivery's date can now be corrected from its detail page. Saving
marks it as manually set, so a later re-upload of the packing list cannot quietly
overwrite what a person entered, and immediately recalculates the turnaround of every PO
in that delivery. It needs `manage-fulfillment`; everyone else sees the date read-only.

### 9.5 Verified against the real files

The screens were rendered against the genuine sample import, not only test fixtures.
They report 11 POs, 213 lines, **14,117 units shipped** — the same figure M3 verified by
hand — and a 3,449-unit shortfall, which is the known 623 on the multi-delivery PO plus
2,826 on the ten bulk POs whose packing lists have not been supplied. Every number on
screen traces back to the engine.

### 9.6 Tests

`ReportScreensTest` (26) builds one small world through the real upload pipeline and then
interrogates every screen about it: each screen behind its own permission, Warehouse
seeing units but never AED, each filter genuinely narrowing, group-by rolling up, the
pasted and uploaded bulk lists surviving as shareable links, the CSV contents and their
money gating, the multi-ISA PO view, the committed lookup excluding already-shipped
deliveries, and a corrected delivery date moving the PO's turnaround.
Full suite: **153 passing**.

---

## 10. Decisions made during the build

Recorded here so they are not re-litigated. Anything marked **⚠ assumption** was not
specified in the blueprint and should be confirmed.

1. **One upload permission per file type in the §T registry**, rather than a single
   blanket `upload-*`. Lets rights be handed over gradually.
2. **⚠ assumption — `upload-sellout` and `upload-dfs` are Admin-only** even in the full
   matrix. §O does not mention them. Sales may be the more natural owner of the
   sell-out report; say the word and it moves.
3. **⚠ assumption — Noon upload permissions mirror Amazon's**: `upload-noon-po` follows
   `upload-po` (Procurement), `upload-noon-picking-list` follows `upload-packing-list`
   (Warehouse).
4. **⚠ assumption — new screen permissions.** §M/§R/§S imply screens that §O never
   listed: `view-shipments`, `view-committed-deliveries`, `view-sellout`, `view-dfs`,
   `view-master`, `manage-master`. They have been added with sensible role assignments
   (e.g. Warehouse sees shipments but not sell-out; `manage-master` is Admin-only per §S).
   §O itself is marked "TENTATIVE, first draft", so these are all easy to adjust.
5. **The PIN is a shared config value, not per-user.** §S says "Admin-only + PIN/password"
   without specifying per-user PINs. A shared PIN is simpler and matches the wording;
   per-user PINs can be added later without changing the middleware's shape.
6. **The Phase-0 sketch tables were dropped, not migrated.** They held no real data, and
   keeping them would have meant maintaining two versions of the same concepts. Their
   migration files are deleted so a fresh install never creates them.
7. **`manual_overrides` was removed.** It existed in the sketch, was empty, and appears
   nowhere in the blueprint. The manual entries the blueprint *does* call for — the Noon
   delivery date (§Q) and pallet/carton counts (§D) — are now real columns on
   `deliveries` / `purchase_orders`, each with an `is_manual` flag. If a general
   "override any quantity" feature is wanted, say so and it comes back properly.
8. **⚠ assumption — one cancellation row per PO × SKU, latest upload wins.** This mirrors
   the §C PO upsert rule. If Amazon ever sends a second cancellation email that *adds*
   to an earlier one for the same line, the second upload would replace rather than
   accumulate. Worth confirming against how the emails actually arrive; the fix is a
   one-line change to a unique index.
9. **Derived money columns are stored as imported *and* recomputed.** The master sheet
   ships with profit/margin already calculated. Those values are kept for cross-checking,
   but §S says the app's own calc logic is the source of truth, so M6 recomputes them.
   Any disagreement between the two is a useful data-quality signal.
10. **A packing list is a snapshot, not an increment.** Re-uploading the same stage for
    the same ASN replaces that stage's lines rather than adding to them. Interim and
    final coexist on one delivery. Both covered by tests.
11. **⚠ assumption — a cancellation that would claw back committed units nets *nothing*
    until answered.** §G says the system stops and asks; it does not say whether the
    safely-cancellable part should net in the meantime. Netting nothing was chosen so no
    figure moves behind the user's back. The alternative — net the free part now, ask
    about the rest — is a small change to `AmazonCancellationImporter::decide()`.
12. **Sample data is git-ignored by pattern.** The root `.gitignore` excludes `*.xls`,
    `*.xlsx`, `*.xlsm`, `*.csv` and `samples/` wholesale, because these files are real
    purchase orders and costs and anything committed to git is permanent.

**Added at M4:**

13. **Turnaround measures to the *final* packing list's date.** The interim banner date
    is never used for anything, per §K. If a final has no date, the upload day stands in
    and is marked as inferred rather than presented as exact.
14. **Answering the deliver-anyway question is Admin-only, deliberately.** §O never named
    a permission for this action and the eventual owner has not been decided, so it sits
    with Admin until it is. It has its own permission, `decide-cancellations`, precisely
    so that handing it over later is one line and nothing else moves. Everyone with
    `view-cancelled-items` can still watch the queue and the chargeback exposure.
15. **"Pull it" cannot un-ship.** §G says pulling back is "only possible if not yet
    booked/shipped". Rather than refusing the whole answer, the honourable part is
    honoured and the already-shipped remainder is recorded as delivered anyway — which
    raises the chargeback flag for exactly the units at risk. The alternative (refuse
    the answer outright) leaves the user with no way forward.
16. **Pulling back does not edit the uploaded interim packing list.** The file is a
    record of what was uploaded and stays that way; net accepted drops, and the eventual
    final simply will not contain those units. The screen tells the user to have the
    warehouse leave them off.
17. **`completed_on` is the later of the last shipment and the honoured cancellation.**
    A PO closed by cancelling the remainder was not complete on the day of its last
    shipment.
18. **A human decision survives a re-upload of the same figures**, but a changed
    cancelled quantity reopens the question — that decision was made about different
    numbers. Reopening is reported in the upload's warnings.
19. **⚠ assumption — the typed PO date is optional and never overrides the file.** It
    exists only because the single-PO export has no order-date column at all.

**Added at M5:**

20. ~~**⚠ assumption — any one money permission unlocks AED on operational screens.**~~
    **Superseded after M5 (§14.1): order value is open to every role, Warehouse included.**
    The assumption was that the marketplace value of units belonged to one of §O's three
    money lenses. It belongs to none of them — it is the size of the order, not the profit
    on it — so it is no longer gated at all.
21. **The filter bar POSTs and redirects to a GET link.** A pasted list of thousands of
    ASINs, or an uploaded file, cannot live in a query string — so the list is stashed in
    the session and only a key travels. Every screen stays bookmarkable, pageable and
    exportable with its filters intact.
22. **The date range means the PO's order date** on every screen except Shipments, where
    it means the delivery's date. §L anchors time-based reports on the PO date, but a
    delivery screen filtered by PO date would be surprising.
23. **⚠ assumption — correcting a delivery date needs `manage-fulfillment`.** It changes
    a published KPI (turnaround), so it is not open to everyone who can see the screen.
    Admin, Procurement and Warehouse have it.
24. **Screens never recompute.** They read the engine's cached columns and narrow them.
    Anything that needed new arithmetic (grouped roll-ups, shortfall totals) lives in
    `FulfilmentQuery`, using the same definitions as the engine — so a screen and the
    engine cannot drift.

---

## 11. Open questions

Carried forward from blueprint §H, plus what the build has raised.

| # | Question | Status |
|---|---|---|
| H3 | Final fixed file templates / filenames | **Resolved for Amazon at M3** — all four formats confirmed against real files; filenames stay informational |
| H4 | DB column names vs migrations | **Resolved at M1** — the model was rebuilt |
| — | The ⚠ assumptions in §10 above | Awaiting confirmation, not blocking |
| — | **Cancellation POs are from a different batch** | Netting unproven on real data — see §7.2 |
| — | **The single-PO export has no order date** | **Worked around at M4** — optional PO-date box on the upload form; the bulk export carries it properly |
| — | Correcting a delivery date after the fact | **Done at M5** — editable from the delivery page, marked as manually set, recomputes turnaround |
| — | Sell-through benchmark for the sell-in/sell-out flag (§P) | Needed by M9 |
| — | Weighted-average vs latest supplier cost (§S) | Deferred to Phase 3, as specified |

---

## 12. What M6 needs next

The screens are live; M6 is the master catalog behind them:

- **The master grid** (§S): an Excel-like editable screen inside OperON — click-to-edit
  cells, inline add and delete, saving instantly. Admin-only and behind the PIN. Bulk
  `.xlsx` load kept for mass updates.
- **Loading `Master_Products_Sheet.xlsx`**, which immediately switches on the brand and
  category filters and grouping that M5 built and currently has no data for, and links
  ASINs to their company product code so cross-channel reporting works at all.
- **The net-margin engine**: the sheet's P&L formulas become the app's own calc logic,
  applied to every PO and SKU. Recomputed by us, with the imported figures kept for
  cross-checking (§S).

Then M7 turns that into the money views — PO-level and SKU-level margin, Admin-only and
behind the PIN.

### Useful extra files, when convenient

Not blocking, but each would let something be verified on real data rather than
constructed data:

| File | What it would prove |
|---|---|
| A cancellation sheet naming POs we hold | Netting **and the deliver-anyway queue** on real data (§7.2) |
| The bulk export covering `774FV9FB` / `77Z18X8Q` etc. | The 178 waiting packing lines linking up |
| A PO with `Accepted < Requested` | Confirmation rate — every sample row is 100% |
| The order date for PO `6QT4G44D` | The first real turnaround figure (§8.6) |
| The `Master_Products_Sheet.xlsx` | Needed for M6 anyway |

---

## 13. Things worth knowing about the data

Rules that are counter-intuitive and easy to get wrong — all from the blueprint,
repeated here because they are the source of most potential bugs:

- The PO file's own **"Cancelled quantity" column is never used for netting**. Only the
  uploaded cancellation file nets. Fresh POs always show 0 there.
- Packing lists: read **only the `Simple List` tab**; skip rows whose title is literally
  **"Carton total"** or units double-count.
- Map spreadsheet columns **by header name, never by position** — they shift between
  the interim and final packing lists.
- Packing-list lines whose PO isn't ingested yet are **stored anyway** and reconciled
  later. This is normal during rollout, not an error.
- A cancellation for units **already booked or shipped** must stop and ask the user:
  *deliver anyway* (flag chargeback exposure) or *pull it*. Until they answer, **nothing
  nets** — and the PO stays open.
- Once a cancellation has netted, **a later packing list must never un-net it**. The
  re-judging rules run only on rows nobody has answered and nothing has netted.
- **Booked is not shipped.** Only a *final* packing list ships units, dates a delivery
  and can complete a PO. An interim never does.
- Packing-list cells are formulas; the reader takes the **cached calculated value**.
  Users are never asked to flatten or paste-values.

---

## 14. Changes after M5

### 14.1 Order value is open to every role

Warehouse now sees order value — units × unit cost — on every operational screen and in
every export, alongside Finance, Sales and Procurement.

The reasoning is that **order value and margin are two different numbers from two
different files**, and only one of them is sensitive:

| | Where it comes from | Who sees it |
|---|---|---|
| **Order value** = units × unit cost | The PO file's own `Cost` column — the *marketplace's* price, what Amazon pays us (§C) | **Everyone** |
| **Margin / profit / what we paid** | The master sheet's cost columns (§S) | **Admin only, behind the PIN** |

A warehouse team member looking at a shortfall needs to know whether the units missing off
a truck are worth 400 or 40,000 — that is what decides whether it is worth chasing. The old
rule hid that without protecting anything, since the unit cost involved is the marketplace's
own price and is already printed on the packing lists they handle every day.

Nothing about profitability moved. Cost, profit and margin remain Admin-only and PIN-gated.

`User::canSeeOrderValue()` is the single place the rule lives. It returns `true`; the
`@if` checks stay in the screens and exports so narrowing it again is a one-line change.
