# M9 — Sell-out, stock, velocity and cover (CHECKPOINT)

On `phase-1-build`. **Not merged.**

**313 tests passing, 1,349 assertions, no failures** — all 276 from M8 still green, plus
**37 new** (15 ingest, 22 analytics). All **72 checks** in `operon:verify-samples` pass on
the real files.

All five M9 files were present with exactly the expected names and date ranges.

---

## 1. The five files, ingested

| File | Rows | What came out |
|---|---|---|
| `Sales_ASIN_Sourcing_Retail_…` | 554 | 84,434 units · **AED 1,704,390.15** · 1 Jun – 5 Aug (66 days) |
| `Inventory_ASIN_Sourcing_Retail_…` | 793 | SOH 61,917 · aged 90+ **4,072** · open PO 69,001 · net received 127,114 |
| `DFS Sales_1st July to 4th Aug` | 1,731 | 1,863 units · AED 45,940.54 · 787 daily rows, 1 Jul – 4 Aug |
| `amazon_df_inv_bulk_…csv` | 510 | 22,521 units, **every row flagged provisional** |
| `Noon Sell out_BD Sell Out & SOH` | 6,865 | 23,274 units · AED 391,152.96 · 500 stock rows · 529 barcode↔NIN map entries |

Every one of those totals was checked against an independent read of the raw file.

## 2. The column that would have been wrong and never looked wrong

**Amazon sell-out revenue is `Shipped COGS`, not `Shipped Revenue`.**

```
OUR revenue — "Shipped COGS"    (what Amazon paid us)      AED 1,704,390.15
NOT ours    — "Shipped Revenue" (what the customer paid)   AED 1,691,050.50
```

They are **0.8% apart**. Taking the wrong one would not look wrong on any screen — it
would quietly understate the channel and every ratio built on it. So `revenue` is a named
column holding ours, `revenue_basis` records which source column it came from, and
`verify-samples` prints both side by side every run.

## 3. The 598% bug, and why sell-through is sometimes blank

The first working build reported **Amazon sell-through of 598%** and **Noon 363%**. Neither
was a bug in the arithmetic — it was two unrelated spans being divided.

Amazon's sell-out covers 66 days. **Nine of the eleven Amazon deliveries we hold are dated
15–20 Aug — after that window closes.** Dividing one by the other measures which files have
been uploaded, not how the channel sells. That number would have sat on the Overview tile
looking like a triumph.

So a sell-through figure is now only reported against a denominator covering **the same
days**, chosen in this order:

1. **The channel's own received count.** Amazon's inventory report carries `Net Received
   Units` for exactly the sell-out window: **84,434 ÷ 127,114 = 66.4%**, with **42,680
   units sitting at the channel**. Aligned by construction, and Amazon's own count.
2. **Our own shipped lines dated inside the window** — but only when they span a fair part
   of it.
3. **Nothing.** Both unit counts are still shown; only the ratio is withheld, with the
   sentence saying which upload would produce it.

| Channel | Sell-through | Why |
|---|---|---|
| Amazon Retail | **66.4%** | Amazon's own Net Received Units, same window |
| Amazon DFS | *not reported* | no sell-in step — the order **is** the sale, so it would be 100% by construction |
| Noon Retail | *not reported* | 61-day sell-out against shipments on **1 day** inside it |

Noon really did sell 23,274 units; we simply do not hold the POs that stocked most of them.

## 4. Velocity — three channels, three qualities of answer, all labelled

| Channel | Run rate | Basis |
|---|---|---|
| Noon | Noon's own **L7_DRR** | stated by the channel — beats anything we derive |
| DFS | trailing **L7**, falling back to L30 | dated orders, **anchored on the data's last day, not today** |
| Amazon | units ÷ window days | **a PERIOD AVERAGE** — the report has no daily detail, and every row says so |

The DFS anchoring matters: the extract ends 4 Aug and was read on the 7th. Counting back
from today would have scored three days of zeroes against every SKU and cut every DFS rate
by a third — a wrong answer that looks entirely reasonable.

**A zero run rate gives no cover, not infinite cover.** A sentinel would sort straight to
the top of every table.

## 5. Days of cover, and the watchlists

```
Amazon Retail   61,917 units  1,279.30/day   48.4 days   4,072 aged 90+
Amazon DFS      22,521 units      53.23/day  423.1 days   (provisional)
Noon Retail     25,532 units     381.54/day   66.9 days
```

**Overstocking reaches the list three ways**, because a SKU can be overstocked in three
shapes and one rule misses two of them:

| Route | SKUs |
|---|---|
| more than 90 days of cover | 281 |
| **stock that sold nothing across the window** | **243** |
| Amazon says it has aged 90+ days | 44 |
| **total** | **568 SKUs, 55,148 units** |

That middle row is the one that was nearly missed. 243 Amazon ASINs hold stock and are
**absent from the sell-out report entirely** — they have no run rate to be low, so no
cover-based rule can ever see them, and they are the worst overstock we have. Their absence
from the report is a fact, not missing data, and is now read as such.

**Under-supplying: 150 SKUs** — Noon 68, Amazon 44, DFS 38. The sharpest is
`Silverpot Drain Opener - 500g` on Noon: **out of stock, still selling 40 a day.**

## 6. Bundled fix 1 — the Noon delivery date is never invented

M8 stood the **upload day** in for a missing Noon delivery date and marked it "inferred". An
inferred date still drives turnaround, still averages into the benchmark, and still reads
like a fact. The day a file happened to be uploaded is not evidence of when goods moved.

**The 23-Jul date is gone and the fallback with it.** A shipped Noon delivery now reports
**no fulfilment date and no turnaround** until somebody types the real one. Noon's own
Estimated Delivery Date (22 Jul) shows as a placeholder labelled
**"estimated (not confirmed)"** everywhere it appears, and the delivery lands on Overview's
"Act today" list so the gap is visible rather than silently filled.

Amazon is untouched — its final packing list states a real shipment date.

## 7. Bundled fix 2 — what is not in the master, by name

M8 said "one Noon PO line has a NIN that is not in the master". Here it is:

```
NIN Z2711427219A2E6791868Z-1
Brandsfinity Facial Tissue Cube Box, Pack of 48
36 units — ordered on PO 287285145169960 AND delivered
```

M9's feeds turned that one line into **194 identifiers, 30 of which we have actually
ordered, delivered or sold** — including `Koala Picks 250g Almond Butter` (389 units, on all
four) and 12 `Festiva` ASINs.

It is **derived, not stored**, and that is deliberate: the master importer clears and
rebuilds its anomalies on every upload, so a stored flag would be wiped by the very upload
most likely to have fixed it — and would go stale the other way too. Computed from the fact
tables, an entry appears the moment a file names an unknown SKU and disappears the moment
the catalog learns it. Nothing to dismiss.

## 8. DFS stock is provisional, in the data

Every DFS stock row is stored with `is_provisional` and the note
**"provisional — pending internal-tool link"**. The flag rides on the row rather than being
inferred from the channel, so no screen can show the number without the caveat — and none
does: the tile, the channel table, the watchlist rows and the quadrant dots all carry it.
No deeper DFS stock logic was built, deliberately; that waits on the OperON ↔ in-house-tool
integration.

## 9. One bug this found in itself

Stock joined by `whereDate()` against the max snapshot date. The importers write that column
with a bulk `insert()` (storing `2026-08-05`) while anything via `create()` goes through the
date cast (storing `2026-08-05 00:00:00`) — and feeding the second form back into
`whereDate()` matches **nothing**. Every stock figure would vanish and cover would read "—"
everywhere, depending on nothing but how the row happened to be written. Normalised once, in
`InventorySnapshot::latestDateFor()`.

---

## What to eyeball

1. **`/overview` — the two new tiles.** Sell-through **66.4%**, days of cover. Then the
   sell-through panel: Amazon has a percentage, **DFS and Noon say in a sentence why they
   do not**. That is the point of the panel, not a gap in it.
2. **`/overview` → "Stock and days of cover".** Three channels, DFS tagged **provisional**.
   Check the aged-90 column reads 4,072 for Amazon.
3. **`/products#watchlists`.** Overstocking (568) and under-supplying (150). Every row
   carries **why** it is there — "119 days of cover on 2,377 units" vs "968 units, nothing
   sold in 35 days" vs "2,377 units aged 90+ days, per Amazon" are three different
   conversations. Find the Noon drain opener: **out of stock, still selling 40 a day**.
4. **The quadrant on `/products`** — now velocity against stock, with named corners.
   Bottom-right "RUNNING HOT" is reorder-now; top-left is stock going nowhere. Faded dots
   are DFS.
5. **`/master`** — the new panel above the grid: 194 identifiers not in the catalog, 30
   traded. `Z2711427219A2E6791868Z-1` is the Noon one. Add it and watch the entry vanish.
6. **`/deliveries`** — the Noon delivery now shows **22 Jul 2026 · estimated (not
   confirmed)**, and `/deliveries/{id}` explains it and offers the field. Type a date and
   the PO's turnaround appears.
7. **The channel selector on `/overview`** now offers all three channels — DFS was missing
   from the quick toggle before M9.
8. **`/uploads`** — five new/live types: Amazon sell-out, Amazon inventory, DFS sell-out,
   DFS inventory (CSV), and **Noon Sell-out & SOH** (one workbook, both halves — the file is
   literally named that, and splitting it would mean uploading it twice for one answer).
9. **Money views are unchanged** — nothing on the new panels is cost or margin, so nothing
   new sits behind the PIN. Currency is AED throughout.

## Running it

```bash
php artisan operon:verify-samples --dir=/workspaces/Operational-Dashboard --fresh
php artisan test
```

Sample files in `M9_Sellout_DFS/`. `admin@demo.local` / `password`, money PIN `1234`.

## Open, for you

- **Noon and DFS sell-through need more history.** Upload the Noon POs and packing lists
  covering June–August and Noon's percentage appears on its own. Nothing else is needed.
- **The cover thresholds are a starting point** — 90 / 14 / 30 days in
  `config('operon.cover')`, chosen against these files. They are commercial judgement, not
  arithmetic; change them there.
- **DFS cover of 423 days** is arithmetically right and rests on provisional stock. Worth
  treating as a shape, not a figure, until the in-house tool is linked.
- **`Koala Picks 250g Almond Butter`** appears under a **barcode**, not an ASIN or NIN, on
  all four feeds — worth a look at where that row is coming from.
