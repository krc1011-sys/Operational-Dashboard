# M8 — Noon Retail channel (CHECKPOINT)

On `phase-1-build`. **Not merged.**

**276 tests passing, 1,177 assertions, no failures** — all 243 from M7 still green, plus
**33 new** (21 Noon, 12 bundle-component). Every F) target reproduced on the real
PO 287285145169960, checked by `operon:verify-samples`.

---

## 1. The rule that decides whether M8 is right or catastrophically wrong

**Noon annotates only the exceptions.** A line missing from the picking list was
**delivered in full**. This is the reverse of Amazon, where a packing list is a positive
record and absence means nothing shipped.

On the real PO the difference is not academic:

| Read it… | Delivered | Fill rate |
|---|---|---|
| the Amazon way — only what the file lists | 5,812 | **90.37%** |
| **correctly** — plus the six lines it never mentions | **6,402** | **99.55%** |

Six lines that went out *perfectly* would have been reported as never sent, and somebody
would have gone and chased them. `operon:verify-samples` now asserts both the right answer
**and prints the wrong one it would have been**, so the mistake cannot come back quietly.

## 2. Verified against the real file

```
✓ Ordered lines (Packing List)                72   expected 72
✓ Ordered units                            6,431   expected 6,431
✓ Order value (the file's own line totals)  107,694.05   expected 107,694.05
✓ Delivered units                          6,402   expected 6,402
✓ Shortfall units                             29   expected 29
✓ Fill rate %                              99.55   expected 99.55
✓ Short lines                                  1   expected 1
     barcode 716841215014  ordered 221, delivered 192, short 29  — 3 pcs Drain Opener & Cleaner
✓ Lines the picking list states               66   expected 66
✓ Lines delivered in full by omission          6   expected 6
```

**All 60 checks across the whole sample set pass; none regressed.**

## 3. How the four tabs are read

Every Noon workbook carries the **same four tabs whichever stage it is**, so the tab a
definition reads is what distinguishes the upload types — the filename is not enforced (§T).

| Tab | What it is | How it is found |
|---|---|---|
| *(PO number)* | The PO header — partner, PU, currency, dates, VAT no | **the tab that is not one of the other three** |
| **Packing List** | **THE ORDER.** UOM Qty, Unit Rate, Total Amount | by name |
| **Picking List** | **THE DELIVERY.** Qty, OG qty | by name |
| Short Titles | barcode → title reference | ignored, as specified |

**Noon's naming is the reverse of Amazon's** — Packing List is the order, Picking List is
the delivery — and that reversal is called out at the top of both importers.

Nothing is read by column position, and the real file proves why: the **interim** picking
tab is 7 columns with `Barcodes` in column 3, while the **final** is 9 with an
**unlabelled** consignment-reference column in between, putting `Barcodes` in column 4 and
adding `OG qty`. Same header names, different positions. The M3 header-name parser handles
both unchanged — no parser was rebuilt.

**The two tabs join on barcode, and they spell it differently**: `642135123720` on the
packing tab, `0642135123720` on the picking tab. Joining raw strings matches nothing, and
an unmatched picking row looks exactly like a line that was never delivered — a silent,
confident, catastrophic failure. `App\Support\Barcode` normalises for comparison and keeps
Noon's own spelling for display.

**The order comes from the workbook's own packing tab, not the database**, so a picking
list uploaded *before* its PO still produces the right delivered figures. That removes an
entire class of upload-order bug, and there is a test for it.

## 4. Four things the real file taught us

1. **`Final Cost` is a rounded display value.** It is the VAT-inclusive rate rounded to the
   fils — 2.89 where the true rate is 2.8875 — so multiplying it back up put the PO
   **6.57 out**. The unit cost is taken from the line's own `Total Amount` instead, and
   units × unit cost now reconciles with what Noon invoiced.
2. **An empty picking list is a valid file, and a *good* one.** It means "no exceptions",
   i.e. everything went in full. The validator was rejecting it as "a header with no data
   rows"; Noon's two picking types now declare `allowsNoDataRows`.
3. **A Noon PO can go straight to a final with no interim.** That made every delivered Noon
   line report its whole quantity as *not booked* — units sitting on the Not-booked tab
   that had already gone out of the door. Shipped units are now booked by definition.
4. **A delivery with no invoice banner was worth zero.** Noon carries no "Invoice value"
   banner, and the fallback `$delivery->value_final ?: sum(...)` never fired because the
   decimal-cast column reads as the string `"0.00"`, which is truthy. Fixed for both
   channels.

## 5. Wired into everything downstream (§E)

Nothing needed a Noon special case, because Noon writes the same PO lines, shipment lines
and deliveries every other channel does:

- **Overview channel mix** — Noon: 1 PO · 6,402 units · **AED 106,842.96** · 99.5% fill.
- **Fill rate, shortfall, turnaround, PO Lookup, Fulfilment, Deliveries** — all live.
- **Confirmation rate reads "n/a" for Noon**, not 100%. Noon has no accept step, so a
  percentage there would advertise a negotiation that never happened.
- **Profitability** — Noon P&L: billed 106,842.96 → net 82,328.96 − cost 60,548.54 =
  **21,780.42, margin 26.46%**, 71 of 72 lines costed. The M7 engine needed no change; the
  Noon rates (front 0.98, back 0.78, FnB 0.80) were already in it.
- **71 of 72 lines link to the master catalog by NIN** — the master sheet's own
  "Customer Product Code (Noon)".

**The Amazon / Noon / Both selector now does real work.** Before M8 no product had shipped
units on more than one channel, so every blend fell back to per-unit weighting. Now **12
products carry real volume on both**, e.g.:

```
BD07114444   Amazon Retail  114 units  13.30%
             Noon Retail    367 units  17.45%
             BLENDED (revenue-weighted)  16.53%     ← a simple mean would say 15.38%
```

That is the M7 rule finally being exercised on money we actually banked.

## 6. Bundled fix 1 — DESIGN_BRIEF §8

Doc-only. The brief's IA list said **Margin**; the app and the blueprint's own
§Profitability heading both say **Profitability**. The brief now matches, with a note
saying which name came first.

## 7. Bundled fix 2 — "Bundle component (not sold standalone)"

`BD07903074` topped the losing-SKU list at **−33.31%**: a phantom loss for a product that
has never been sold at a loss because it has never been sold at all.

**A flag, not a deletion, and it changes no figure.** The product keeps every cost and
purchase number, everywhere — master grid, PO cost stack, P&L, exports. What it changes is
**ranking**: flagged products are held out of margin league tables and loss watchlists,
where a meaningless percentage crowds out a real one, and their margin reads
**"N/A — bundle component"** rather than a number nobody should act on.

- **A toggle in the master grid**, so you can flag the handful yourself. Writing it needs
  `manage-master` **and** the PIN, like every other master edit.
- **`BD07903074` is seeded** from `config('operon.bundle_components')`, applied when a
  product is **first created** by a master import — so a later upload never overwrites a
  flag you set or cleared by hand.
- **Losing SKUs went from 21 to 20.** The real losses are still there; the phantom is not.
- Full cost-rollup into the parent bundle is Phase 2/3 — it needs a bundle-to-component
  mapping the catalog does not carry. This flag is what makes the screens honest meanwhile,
  and it is the column that mapping will hang off.

---

## What to eyeball

1. **`/po-lookup/287285145169960`** — 72 lines, 6,431 accepted, 6,402 shipped, **99.55%**.
   Find the drain cleaner (221 → 192). Everything else should read as fully delivered —
   including the six lines Noon never mentioned.
2. **`/overview` → channel mix.** Noon now sits beside Amazon: 6,402 units, AED 106,842.96,
   99.5%. Confirmation rate should read "—" for Noon, not 100%.
3. **`/money?view=sku` → the Both / Amazon / Noon toggle.** Look at `BD07114444`: expand
   its channel rows and check the blend sits at 16.53%, nearer Noon's 17.45% because that
   is where the units are. This is the first time the revenue weighting has had real data
   on both sides.
4. **`/money/po/287285145169960`** — the Noon P&L. Margin 26.46% against Amazon's 18.13% on
   the same catalog, because Noon's front margin is 0.98 against 0.9019.
5. **`/master?q=BD07903074`** — the new **Bundle component** column, ticked. Its cost still
   shows 4.0000. Then `/money?view=sku` and confirm it is no longer in the losing list but
   is still findable, marked "N/A — bundle component".
6. **Try the toggle** on another SKU and watch it leave the ranking.
7. **`/uploads`** — the three Noon types in the dropdown, and the **Delivery date** field
   that appears when you pick a Noon picking list.
8. **The one unlinked Noon line** (1 of 72 has a NIN not in the master) — worth adding.

## Running it

```bash
php artisan operon:verify-samples --dir=/workspaces/Operational-Dashboard --fresh
php artisan test
```

Sample files in `M8_Noon/`. `admin@demo.local` / `password`, money PIN `1234`.

## Open, for you

- **The delivery date is typed at upload.** Noon's files carry only their own *estimated*
  delivery date, which is a plan. The upload form now asks for the real one; left blank,
  the delivery shows the upload day and marks it inferred rather than inventing a date.
  I used **23 Jul 2026** for the sample — correct it if that is wrong.
- **`/money?view=sku` takes ~3s** on the full catalog, since sorting by margin means costing
  all 914 products. Fine for an admin screen; worth revisiting if the catalog grows.
- **Noon cancellations** have no file yet, so §G's queue is still Amazon-only.
