# M6 — Master catalog and net-margin engine

On `phase-1-build`. Margin corrected to the vendor model after review.

**203 tests passing, 43 verified checks against the real files, no failures.**
Money displays as plain **"AED 1,234.50"**.
M3–M5 figures unchanged: 14,117 shipped, 95.77% fill.

---

## The real file decided the schema

`OperON_Master_Merged.xlsx` is one row per product **× channel** — 1,979 rows covering
914 products — because the same product earns differently depending on who sells it (the
marketplace keeps 29.65% of retail on Amazon against 23.56% on Noon, at different invoice
prices). 727 of the 914 sell on more than one channel.

So the money columns moved off `products` onto `product_channel_economics`, one row per
(product, channel), while **identifiers stay per marketplace**. That split is
load-bearing: an ASIN is one ASIN whether it sells through VC or DFS, so the **641 ASINs
shared between the two Amazon channels** get one identifier row and two sets of numbers,
losing neither.

Loaded from the real file: **914 products · 1,978 channel rows · 1,354 identifiers**
(821 Amazon + 533 Noon) · 29 brands · 20 categories · 56 sub-categories.

## M5's filters switched on

Loading the catalog **linked 189 of the 213 existing PO lines to a product**. That is the
moment the brand and category filters and group-by — which M5 built and which had been
honestly reporting "not in the catalog yet" — started working on real data. Nothing in M5
changed; the `product_id` column it already read simply stopped being null (§K).

## The engine: the vendor model

**We are a vendor, not a Seller-Central seller.** Amazon VC, Amazon DFS and Noon Retail
all *buy* from us. The marketplace keeps two margins and nothing else:

```
Invoice value   = RSP ex VAT × front margin     0.9019 Amazon · 0.98 Noon
Net receivable  = Invoice    × back margin      0.78 standard
Net margin      = Net receivable − our costs (product + marketing + OPEX + packaging)
```

**The five Seller-Central fee columns are never deducted.** They are stored because the
file carries them, and ignored. A test sets all five non-zero *and* the blended rate to
99%, then asserts the answer does not move.

Rates are stored **per row**, because the real file proves they vary:

| Channel | Front | Back | Rows | Marketplace keeps |
|---|---|---|---|---|
| Amazon Retail | 0.9019 | 0.78 | 748 | 29.65% |
| Amazon DFS | 0.9019 | 0.78 | 643 | 29.65% |
| Noon Retail | 0.98 | 0.78 | 382 | 23.56% |
| **Noon food (FnB)** | 0.98 | **0.80** | **151** | **21.60%** |
| Amazon DFS outliers | 1.0 | 0.80 | 5 | 20.00% |

VC and DFS are identical. The 151 Noon rows banking 0.80 are **all `Category = FnB`**, and
their own fee column agrees at 21.6%. **Confirmed correct, not an error** — the 22% / 23.56%
figures stay as the channel defaults for anything the file does not state. Hardcoding 22%
would have overstated the marketplace's cut on every one of the 151.

### Reconciliation with the sheet's own columns

| Check | Result |
|---|---|
| RSP × front margin = the sheet's invoice column | **1,929 of 1,929** |
| Invoice × back margin = the sheet's net-receivable column | **1,929 of 1,929** |
| Profit | 1,928 of 1,928 |
| Margin % | 1,928 of 1,928 |
| COGS | 1,928 of 1,977 — **49 differ, all flagged** |

Those 49 are the packaging materials, where the sheet totals cost as zero while each
plainly has one. **Ours is right** and every one is on the review list. `verify-samples`
asserts the property that matters: no disagreement is silent.

Things we buy and never sell report **no margin rather than 0%**.

## Your three Data_Flag cases — surfaced, none silently fixed

- **`BD62972744`** — one code covering two products. Flagged on both its channels, with a
  note that its profit is unreliable until the two products are split apart.
- **`BD07965870`** — a Noon row holding an ASIN. Caught **twice**: by carrying the file's
  own flag through, and by an independent shape check. Stored as a **Noon** identifier
  exactly as given and **not moved to Amazon** — a typo and a mis-filed listing need
  opposite fixes, and only a person knows which it is.
- **Three products costed differently per channel** — 43.87/45, 40/43, 12.50/13.

Also found without being asked: a duplicate row, 98 identifiers that are neither ASIN nor
NIN shaped, and the 49 COGS gaps. **Casing is not treated as a disagreement** — all 73
brand differences are `BRANDSFINITY` vs `Brandsfinity`, and flagging those would have
buried the three that matter.

Totals: **7 items needing a decision, 191 notes.**

## The grid

Click a cell, leave it, it saves and the derived figures repaint.

- **Only inputs are editable.** The server rejects a typed profit — a figure its inputs do
  not produce is how a spreadsheet starts lying.
- **The PIN gates the money columns and every write, not the route.** Putting it on the
  door would bounce Warehouse off a screen §O grants them.
- **Products retire rather than delete**, so PO history keeps its referent.

## Two parser bugs the real file exposed

Both lost data silently:

1. `Profit %` and `Profit` normalised to the same key, so the percentage column could not
   be addressed by name at all. `%` now normalises to `pct`.
2. The file's own typos — `Net Recievable in Hand`, `Referal Fees` — defeated exact
   matching. The registry now matches the real spellings *and* the correct ones.

## PO-level P&L

**A PO is the invoice**, so the front margin is already in its unit cost. What still comes
off is the **back margin** — we bill 100 and bank 78. On the real 8-delivery PO:

```
invoiced (billed)        223,511.20
costable part            222,996.40
less back margin 22%     − 49,059.21
= net receivable         173,937.19
less our costs           −142,399.50
= net profit              31,537.69      margin 18.13%
coverage: 85 of 86 lines costed
```

Coverage travels with the answer: a line that cannot be costed contributes neither its
cost nor its revenue, so it can never read as pure profit.

> This corrects the first cut of M6, which reported 80,596.90 at 36.14% — overstated by
> the whole back margin, because the billed figure was treated as money received.

## Flag to fix

Clicking a flagged product's code now filters to it, scrolls its row into view, highlights
it and puts the cursor in the first cell — with the flag list collapsed, because you came
to fix one product rather than re-read the list you just clicked.

---

## Running it

Live at <https://hoping-local-scoring-awesome.trycloudflare.com> — `admin@demo.local` /
`password`, money PIN `1234`. The **Master** tab is in the nav; without the PIN it shows
the catalog only, with it the economics and editing.

```bash
php artisan operon:verify-samples --dir=/workspaces/Operational-Dashboard --fresh
```
