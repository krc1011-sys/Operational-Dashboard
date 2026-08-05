Design overhaul — a presentation + information-architecture pass only. No logic, data, or permission changes. Read DESIGN_BRIEF.md and open operon_overview_v3.html — that HTML is the exact visual reference for tokens, spacing and components.

Establish the shared shell first (sidebar nav, colour tokens for light AND dark, the reusable components, one consistent filter bar), then re-skin screen by screen, committing per screen:
1. Overview — rebuild to match operon_overview_v3.html exactly.
2. Fulfilment — PO-centric drill (list POs, expand to lines + booking status, status toggle on top); MERGE the Pending tab in as the "outstanding" filter and remove the separate Pending page.
3. Deliveries — MERGE Shipments + Committed into one screen with a Booked↔Shipped toggle; show FC and which POs sit under each ISA/ASN, with per-SKU shortfall.
4. Products — SKU analytics (sell-in vs sell-out, sell-through, brand/category rollups); the labelled quadrant lives here.
5. Master, PO Lookup, Upload — re-skin to the system; keep the Master flag→product-row jump.
Light and dark throughout. Plain "AED" text, no dirham glyph. Explain each screen in plain language; commit + push per screen.# OperON — Design Brief (v3, locked)

Build every screen to this. The companion file **`operon_overview_v3.html`** is the exact
visual reference for tokens, spacing, and components — open it and match it. Keep all logic,
data, permissions and PIN gating exactly as they are; this is a **presentation + IA** pass only.

---

## 1. Principles (business-first)
- **Summary before detail; exceptions first.** Lead with "what's the state / what do I do today," then let people drill.
- **Signal over noise.** Fewer, higher-value visuals + a strong "act today" panel beats a wall of charts.
- **Name things concretely.** Show real product names and plain numbers, not abstract charts a teammate has to decode. (The one scatter/quadrant lives only inside Products → analytics, fully labelled.)
- **Encode state in form,** not just number: severity stripes, pills, chips, mini-bars — so what needs attention reads at a glance.
- **Role-aware.** Vitals + alerts are universal; the prominent breakdown can adapt to the viewer (Ops → FC; Sales/Marketing → brand & sell-through; Management → channel & margin). Ship the universal version first; role-adaptation can follow.

## 2. Brand
- **Logo** (teal linked-node mark + `OperON` wordmark, middle O teal) — top-left of the sidebar. SVG is in `operon_overview_v3.html`.
- **Primary teal `#0d9488`**, **amber `#f59e0b`** accent (rush / attention).
- Currency: **plain "AED" text** for now (NO dirham glyph). Keep currency data-driven so **SAR** slots in for KSA later.

## 3. Colour tokens (use CSS variables; light + dark both required)
Copy the exact `:root` / dark / `[data-theme]` token blocks from `operon_overview_v3.html`. Summary:
- Ground `--bg` cool near-white / near-black; cards `--surface`; hairline `--border`.
- Neutrals carry a slight teal bias (chosen, not default grey).
- **Accent = teal** (brand). **Semantic is separate:** `--good` green, `--warn`/amber, `--bad` red — for status, never as the accent.
- Every colour must work in **light and dark**; the viewer's toggle stamps `data-theme` and must win over the media query.

## 4. Typography
- System stack (`-apple-system, "Segoe UI", Roboto, …`). Two weights in play: 550–650 for labels/headings, 700–750 for figures.
- **`font-variant-numeric: tabular-nums`** everywhere digits align.
- Headings 14–18px/700; KPI figures ~24px/750, letter-spacing tight; body 12–13px.

## 5. Components (match v3 exactly)
- **KPI tile:** left severity stripe (teal/green/amber/red/neutral), tiny label, big figure, one line of context with a `chip` (▲/▼ vs target/prior).
- **Panel:** rounded card, soft shadow, header = title + sub-caption + optional right-aligned action link.
- **Sell-through block:** headline % + a shipped-vs-sold bar, then **two named watchlists** — *Overstocking (pause reorders)* and *Under-supplying (reorder/push)* — each row = product name · plain metric · a right-aligned tag (AED tied-up / units short).
- **"Act today":** severity-dotted alert rows, **ranked by impact**, each with a one-line action link. Lead alerts with the money impact.
- **FC section:** simple bar chart (rush FCs in amber with a RUSH tag) + a compact table (POs · units · value · fill-rate mini-bar).
- **Channel mix:** row per channel with a coloured badge (AMZ / DFS / NOON), sub-metrics, right-aligned revenue.
- **Tables:** uppercase faint headers, hairline rows, right-aligned numerics, hover highlight, row → drill.
- **Filter bar:** one consistent, collapsible set on every data screen (see §7).
- Pills, chips, badges, mini-progress bars — all as in v3.

## 6. Charts
Inline SVG (or a light self-contained lib). Clean: faint dashed gridlines, an area fill under lines, an emphasised endpoint dot, teal primary / amber secondary. No 3-D, no clutter. Pair every chart with the numbers.

## 7. Filters — consistent + self-serve on every data screen
Date range (state which date it means) · Channel (Amazon / DFS / Noon / All) · Region (UAE now, SAR/KSA-ready) · FC (multi) · Brand / Category / Sub-category · Status · SKU/ASIN/NIN/barcode search **+ bulk-paste** · Supplier · Compare-to (prior / target). Plus **Group-by** and **Export** where relevant. Design in **Saved Views** (name a filter combo as a report) even if wired later.

## 8. Information architecture (the IA fixes)
Re-skin AND restructure:
- **Overview** — rebuild to match `operon_overview_v3.html` (vitals · sell-through watchlists · act-today · FC · channel mix · in-flight-vs-complete clarity).
- **Fulfilment** — **PO-centric drill**: list of POs → expand to its lines + booking status, with a status toggle on top (All / Not-booked / Booked / Shipped / Cancelled). **Merge "Pending" into this** as the "outstanding" status filter — remove the separate Pending tab.
- **Deliveries** — **merge "Shipments" + "Committed"** into one screen with a Booked ↔ Shipped toggle; show **FC** and **which POs sit under each ISA/ASN**, with per-SKU shortfall.
- **Products** — SKU analytics home: sell-in vs sell-out, sell-through, ABC/XYZ, brand/category rollups. The labelled sell-through **quadrant** lives here (hover shows product + numbers).
- **Margin** — admin-only + PIN; PO- and SKU-level net margin, using the confirmed front+back-margin maths.
- **Master** — the editable grid; **flagged review items link to the product row** (jump-to-fix).
- **PO Lookup, Upload** — re-skinned to the system.

## 9. Approach
Establish the **shared shell** first (nav, tokens, components, filter bar), then apply screen-by-screen starting with **Overview** (match v3), then Fulfilment, Deliveries, Products, Master, PO Lookup, Upload. Commit per screen. Light + dark throughout. Nothing about the data or permissions changes.
