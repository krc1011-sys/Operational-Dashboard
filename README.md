# Operational-Dashboard
Operational dashboard for B2B e-commerce distribution — PO tracking, fill rate analysis, SKU-level insights, and sales forecasting for Amazon/Noon marketplace fulfillment.

The Laravel application lives in [`backend/`](backend).

| | |
|---|---|
| **Run it locally** | `cd backend && cp .env.local.example .env` — see the header of that file |
| **Deploy it** | [DEPLOY.md](DEPLOY.md) — Laravel Cloud, managed MySQL, auto-deploy from `phase-1-build` |
| **How it works** | [DOCUMENTATION.md](DOCUMENTATION.md) |
| **The rules it implements** | [03_LOGIC_BLUEPRINT.md](03_LOGIC_BLUEPRINT.md) |
| **How it looks** | [DESIGN_BRIEF.md](DESIGN_BRIEF.md) |

> The real workbooks contain genuine purchase orders, costs and invoice values. Every
> `.xlsx` / `.xls` / `.csv` is git-ignored repo-wide and must stay that way.
