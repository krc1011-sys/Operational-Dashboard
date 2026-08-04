# OperON — Project Documentation

**Living document.** The agreed spec is [`03_LOGIC_BLUEPRINT.md`](03_LOGIC_BLUEPRINT.md).
This file records how the spec has actually been built: what exists, how to run it,
what was decided during the build, and what is still open.

Last updated: **M1 complete**.

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
composer install
npm install
php artisan migrate --seed     # creates tables, roles and demo users
npm run dev                    # in one terminal
php artisan serve              # in another
```

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
| **M2** | Upload framework + parser core | ⬜ Not started |
| **M3** | Amazon ingest — **checkpoint, needs real sample files** | ⬜ Not started |
| M4 | Reconciliation engine (fill rate, shortfall, turnaround) | ⬜ |
| M5 | Core screens with self-serve filters | ⬜ |
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
repo's source of truth for §O. 28 permissions across 5 roles, grouped as
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

## 6. Decisions made during the build

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

---

## 7. Open questions

Carried forward from blueprint §H, plus what the build has raised.

| # | Question | Status |
|---|---|---|
| H3 | Final fixed file templates / filenames | Being locked per parser as M2–M3 proceed |
| H4 | DB column names vs migrations | **Resolved at M1** — the model is being rebuilt |
| — | The ⚠ assumptions in §6 above | Awaiting confirmation, not blocking |
| — | Sell-through benchmark for the sell-in/sell-out flag (§P) | Needed by M9 |
| — | Weighted-average vs latest supplier cost (§S) | Deferred to Phase 3, as specified |

---

## 8. Things worth knowing about the data

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
  *deliver anyway* (flag chargeback exposure) or *pull it*.
- Packing-list cells are formulas; the reader takes the **cached calculated value**.
  Users are never asked to flatten or paste-values.
