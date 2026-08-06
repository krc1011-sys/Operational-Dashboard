# M7 — Money views (CHECKPOINT)

On `phase-1-build`. **Not merged.**

**240 tests passing, 951 assertions, no failures** — 207 existing, all still green, plus
**36 new**. The PO-level P&L reproduces **18.13%** on the real reconciled PO, checked by
`operon:verify-samples` against the real files.

M7 is **views over M6's engine**, not a second calculation. `NetMarginEngine` stays the
only thing in the app that works out a margin; nothing on these screens does arithmetic of
its own. If a figure here ever disagreed with the same figure on the master grid, the app
would be worth less than the spreadsheet it replaces.

---

## 1. PO-level P&L

A statement read top to bottom, where every line takes from the one above it. On the real
8-delivery PO `6QT4G44D`:

```
  Invoiced to the marketplace              AED 223,511.20
    Less the part we cannot cost               −  514.80    1 line, 15 units, not in the catalog
  Costable invoice                         AED 222,996.40
    Less the marketplace's margin           − 49,059.21     the back margin, 22% of the invoice
  Net receivable                           AED 173,937.19
    Less product cost                       − 98,132.09     at the latest supplier price
    Less marketing                          − 19,807.70
    Less opex                               − 18,584.93
    Less packaging                          −     75.33
    Less other                              −  5,799.45
  NET PROFIT                               AED  31,537.69   margin 18.13%
```

Verified by the command, not by hand:

```
✓ Invoiced (billed)          223,511.20   expected 223,511.20
✓ Net receivable             173,937.19   expected 173,937.19
✓ Our cost                   142,399.50   expected 142,399.50
✓ Net profit                  31,537.69   expected  31,537.69
✓ MARGIN %                        18.13   expected      18.13
✓ Lines costed                       85   expected         85
✓ Cost lines total the cost  142,399.50   expected 142,399.50
```

**The lines add up to the bottom line, and a test asserts it.** The itemised deductions
are accumulated in the same loop as the total they make up and rounded before being
totalled, so what a person adds up on screen is what is printed underneath — to the fils.
A statement that does not add up is not believed, correctly.

**Coverage travels with the answer.** A line the catalog cannot cost contributes neither
its cost nor its revenue, so it can never read as pure profit — and the fact that it was
left out is a row in the statement and a column in the list, never a silence.

## 2. SKU-level margin — "Both" is revenue-weighted

Per product, per channel, with the **Amazon / Noon / Both** selector. "Amazon" is itself
two channels (VC and DFS), so it gets the same weighting.

```
Σ (weight × profit)  ÷  Σ (weight × net receivable)
```

which is the revenue-weighted mean by construction, because the weights **are** the
revenues. On the real catalog:

```
914 SKUs with economics · 844 profitable · 21 losing money · 49 with no verdict
Blended margin, REVENUE-WEIGHTED  18.23%      a simple mean would say 18.82%
```

Two different numbers, and the blueprint asks for the first. The tests pick figures where
the gap is wide enough to matter: 30% on 100 units of Amazon and 5% on one unit of Noon is
**29.75%**, not the 17.5% a simple mean would report — the difference between a product
that is doing fine and one on the chopping block.

**Unit costs blend over units, not over money** (the blueprint's own caveat) — a cost per
unit weighted by revenue would flatter whichever channel charges the most.

**The weight is real recorded revenue** — units shipped × what we bank per unit. A SKU with
nothing shipped on the channels in view has no recorded revenue to weight by, so the blend
falls back to one unit of each channel; that is still a revenue weighting, and **every row
says which basis it used**. A blended margin whose weighting you cannot see is a number you
cannot check.

> Noon volumes arrive at M8 and DFS at M9. Until then only the Amazon Retail side of a
> blend can carry shipped units, and the screen says so rather than letting it be inferred.

**Products M6 flagged carry the flag onto this screen.** `BD62972744` — one code covering
two products — shows its margin with *"flagged — check before trusting"* linking to the
master grid. The row is not hidden: the SKU is real and somebody is looking for it.

## 3. Placement — both, as asked

**(a) A dedicated Profitability section** at `/money`, `view-margin` + PIN on the whole
route group. Two views behind one toggle — By PO, By SKU — plus a full statement per PO at
`/money/po/{po}`, and a CSV export of each.

**(b) Extra columns inline, on screens that stay open to everyone:**

| Screen | Ungated, every role | Added when the PIN is in |
|---|---|---|
| PO detail | Order value per line, and the PO's shipped value | Net P&L panel, cost/unit, line cost |
| Products | Sell-in per SKU | Cost/unit, profit/unit, margin, profitable / losing money |

**The PIN is not on those two routes, deliberately.** §O gives both screens to roles with
no money permission at all, so a PIN prompt there would take a screen away to protect
columns those roles were never going to be shown. They ask `MoneyGate` and render fewer
columns instead. The unlock prompt appears **only** for someone holding `view-margin` who
has not entered the PIN — a padlock you have no key to is noise, not security.

## 4. The gate: order value open, margin closed

The split §O asks for, now enforced in one place (`App\Support\MoneyGate`) instead of being
re-derived at each call site:

- **Order value** — units × the marketplace's own unit price. How **big** the order is.
  Open to every role, no PIN. Unchanged from M5.
- **Margin** — cost, profit, margin %. What we **make** on it. `view-margin` **and** the
  PIN, every time, on every screen.

A correct PIN cannot conjure a permission a role does not hold: Warehouse entering it still
sees order value and still sees no margin anywhere, and the Profitability section returns
403. Tested in both directions, because getting it backwards either way is a real failure.

## 5. Unlock for the session

Enter the PIN once; it stays in until logout or **15 idle minutes** (was 30).

The window now slides on **any** authenticated request, not only on the money routes.
Before M7 that made it a timeout on money-screen activity rather than on activity — someone
who unlocked, spent twenty minutes on Fulfilment and came back to PO detail would find the
columns silently gone, which reads as a bug rather than as security. What the timeout
protects against is an unattended screen, and a person clicking around the app is not that.
Touching a session that has already lapsed does **not** revive it, and there is a test for
exactly that.

An unlocked session is visible from every screen — a **"Money showing"** pill in the header,
one click to lock — because "am I currently showing profit to whoever is behind me?" has to
be answerable at a glance now that money is on shared screens.

## 6. Cost depth — one thing differs from the brief

The brief expected Marketing / OPEX / Packaging to read **0**, tagged *"until data added"*.
**On the real master sheet they do not.** 1,797 of 1,978 channel rows carry a marketing
figure and 1,928 carry OPEX, and they are already inside the COGS that produced M6's
18.13% — so showing them as zero would have been the fake number the brief was guarding
against.

So the tag is **data-driven, not hardcoded**: a line reads 0 and is tagged only when the
sheet genuinely has no figure for it. On PO `6QT4G44D` that is nothing; on a product with
no packaging cost it is the packaging line. The wiring is the same either way — a test adds
a marketing figure to the sheet and asserts the line fills in with no code change.

## 7. Presentation

v3 tokens throughout, light and dark, no hex codes on any screen. The P&L statement is a
new component (`.pnl`): deductions stepped in and muted, subtotals on a rule, the result on
a heavier rule and the only line at display size — so the workings never read as answers.
Currency is plain **"AED"** text everywhere, from the row's own currency column, so KSA is
still a config change.

## 8. Two caps, both stated

- The SKU table draws the **200 worst margins**. Every matching SKU is still costed — that
  is the only way to know which those are — so the KPIs describe all of them, and the screen
  says what it cut and links to the CSV that carries the lot. A cap nobody is told about
  reads as "this is all of them".
- The PO list pages at 50, as the other screens do.

---

## What to eyeball

1. **`/money` → By PO.** The `6QT4G44D` row: 223,511.20 invoiced → 31,537.69 profit,
   **18.13%**, coverage *85 of 86 lines*. Click through to the full statement and check
   the column adds up.
2. **The statement's cost lines.** Marketing 19,807.70 and OPEX 18,584.93 are real and come
   from the master sheet — confirm those are numbers you recognise, since the brief expected
   zeros.
3. **`/money` → By SKU, and the Amazon / Noon / Both toggle.** Every row shows its channel
   sub-rows underneath so the blend can be checked by hand. Note the weighting label on each
   row and that "Both" ≠ the average of the two percentages beneath it.
4. **The worst margins.** `BD07903074` at −33.31% and the other 20 losing SKUs — are those
   real losses or bad data?
5. **`/po-lookup/6QT4G44D` and `/products` with and without the PIN.** Order value and
   sell-in should be identical either way; only the money columns should appear.
6. **Log out and back in.** The money should be locked again. Then unlock, work on another
   tab for a while, and confirm the columns are still there when you return.
7. **Dark mode**, on the statement and on the SKU table.

## Running it

```bash
php artisan operon:verify-samples --dir=/workspaces/Operational-Dashboard --fresh
php artisan test
```

`admin@demo.local` / `password`, money PIN `1234`.

## Open, for you to settle

- **Whether platform fees belong in a wholesale PO's cost stack** — still open from M6
  (§12.6). They are stored and never deducted today, which is the vendor model.
- **The seven catalog items still waiting for a decision.** They now carry a warning on the
  margin screen rather than being silently included, but the decision is still yours.
- **`/money?view=sku` takes ~2.5s** on the full 914-product catalog, because sorting by
  margin means costing all of them. Fine for an admin screen; worth revisiting if the
  catalog grows several times over.
- **Nav label.** DESIGN_BRIEF §8 lists this tab as *"Margin"*; it is labelled
  **"Profitability"**, following the blueprint's own §Profitability heading and your M7
  wording. Say the word and it is one line.
