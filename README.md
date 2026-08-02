# PO Ops Dashboard

> **Note:** this describes the legacy client-side tool, documented here as the reference implementation. The HTML files themselves are not in this repo. The Laravel rewrite that replaces it lives in [`backend/`](backend/).

Client-side operations dashboard for tracking Amazon and Noon.com purchase orders, packing lists, fill rates, and (admin file only) margins. No install, no server — open the HTML file in a browser.

## Files

- `PO_Ops_Dashboard.html` — full version with Margin/COGS tab (PIN-gated).
- `PO_Ops_Dashboard_Team.html` — same features, no cost/margin code at all. For ops/warehouse/accounting.

## Running it

Just open the HTML file in a browser (double-click, or `File > Open`). No build step, no server. Works offline except for the two CDN script tags (SheetJS, Chart.js) loaded at the top of the file.

## Data

Everything is stored in the browser's `localStorage`. Use **Export backup** in the Data Manager to save a JSON snapshot, and **Import backup** to restore it (or move data to another machine/browser).

## Uploading files

Drop/select a file, then confirm Marketplace + Document type in the popup (auto-guessed, but always requires confirmation). See `DOCUMENTATION.md` for exact formats expected per file type.

## Documentation

See `DOCUMENTATION.md` for the full data model, parsing logic, and reconciliation rules — written for whoever integrates this with the internal tool.
