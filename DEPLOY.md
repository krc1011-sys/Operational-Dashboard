# Deploying OperON to Laravel Cloud

A permanent staging environment that redeploys itself every time `phase-1-build` is
pushed. It holds **real costs and margins**, so everything below assumes that: debug
off, no secrets in the repository, money behind a login *and* a PIN.

- **Deploy branch: `phase-1-build`**
- **Application root: `backend`** (the repository is a monorepo; the Laravel app is not
  at the top level)
- **Database: Laravel Cloud managed MySQL.** Local development stays on SQLite.

---

## 1. Creating the application

1. **New application → connect this repository.** Cloud detects the monorepo and asks
   which top-level directory holds the application. **Choose `backend`.** Every command
   and process below then runs inside it.
2. **Environment → branch `phase-1-build`.** This is what makes it staging rather than
   production-of-the-repo.
3. **General settings → PHP 8.4.** `composer.json` requires `^8.3`; 8.4 is what the app
   is built and tested against. (8.5 is Cloud's default for new environments — change it.)
4. **Add a database → Laravel MySQL.** Name the database `operon`. Attaching it injects
   the connection variables listed below.

---

## 2. Environment variables

### Set these by hand

| Variable | Value to put in |
|---|---|
| `APP_NAME` | `OperON` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — a stack trace on this app prints query bindings, and the bindings are costs |
| `APP_URL` | The environment's URL, e.g. `https://operon-staging.laravel.cloud`. Fill in after the first deploy assigns it |
| `DB_CONNECTION` | `mysql` — **required.** The app's own default is `sqlite` for local work, so without this a deployed environment looks for a file that isn't there |
| `LOG_LEVEL` | `warning` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `FILESYSTEM_DISK` | `local` |
| `ADMIN_EMAIL` | The email address of the first Admin — the login you will use |
| `ADMIN_PASSWORD` | Its password. **Minimum 12 characters**; the bootstrap refuses anything shorter. Generate a random one |
| `MONEY_PIN` | The PIN that opens cost / price / margin screens. Pick your own digits. **Not `1234`** — the deploy is refused if it is left on the example value |
| `OPERON_UPLOADS_ADMIN_ONLY` | `true` (launch rule: every upload is Admin-only) |
| `OPERON_UPLOADS_DISK` | `local` |
| `OPERON_MONEY_PIN_TIMEOUT` | `15` (minutes of idle before the PIN must be re-entered) |
| `OPERON_MONEY_PIN_MAX_ATTEMPTS` | `5` |

`ADMIN_PASSWORD` and `MONEY_PIN` are the two real secrets. Put them in Laravel Cloud
directly (or Secrets Manager). They are never read from anywhere but the environment,
and nothing in the repository contains them.

### Injected by Laravel Cloud — do not set these

`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

Cloud fills these in when the database is attached. A value you type by hand *overrides*
the injected one and will break the next time credentials rotate — so leave all five
alone and let the dashboard manage them.

### `APP_KEY` — check it, don't assume it

Laravel Cloud pre-populates `APP_KEY` in the environment variables when the application
is created. **Look at the variable list and confirm it has a value before the first
deploy.** If it is empty, generate one locally and paste it in:

```bash
php artisan key:generate --show
```

Once set, leave it. Changing it signs everyone out and makes any encrypted value
unreadable.

### Only if you need them

| Variable | When |
|---|---|
| `MYSQL_ATTR_SSL_CA` | `/etc/ssl/certs/ca-certificates.crt`, if the database requires SSL |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION` | Only if you switch `OPERON_UPLOADS_DISK` to `s3` — see §6 |

---

## 3. Build and deploy commands

**Settings → Deployments.**

### Build commands

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Two things worth knowing:

- **`npm ci` needs the devDependencies.** Vite and Tailwind build the entire v3 design
  system and are — correctly — devDependencies. But npm reads `NODE_ENV=production` as
  "skip dev", and on a production deploy that means `npm ci` installs *nothing* and
  `npm run build` fails with `vite: not found`. `backend/.npmrc` now sets `include=dev`
  so this holds wherever the build runs. If you would rather be explicit, use
  `npm ci --include=dev`.
- **The caches belong in *build*, not deploy.** Laravel Cloud discards filesystem changes
  made by deploy commands, so a `config:cache` there would be thrown away. `storage:link`
  is in build for the same reason.

### Deploy commands

```bash
php artisan migrate --force
php artisan db:seed --force
```

`db:seed` is safe to run on every deploy and is meant to:

- **`RolesAndPermissionsSeeder`** — idempotent. It *syncs*, so removing a permission from
  the matrix genuinely revokes it on the next deploy.
- **`AdminUserSeeder`** — creates the first Admin from `ADMIN_EMAIL` / `ADMIN_PASSWORD`,
  or updates it if it already exists. **Changing `ADMIN_PASSWORD` and redeploying is how
  you rotate the password.**
- The five demo logins (`admin@demo.local` … , password `password`) are **not** seeded
  when `APP_ENV=production`. They exist to preview the role matrix locally; on an
  environment holding real margins they would be five ways in.

Do **not** add `php artisan optimize:clear` or `queue:restart` — Cloud handles those.

---

## 4. Auto-deploy on push

**Push to deploy is on by default.** Every push to the environment's branch —
`phase-1-build` — builds and releases a new deployment, with the old one draining
gracefully and zero downtime. Nothing to configure; confirm it under
*Settings → Deployments*.

- Merging to `main` deploys nothing. This environment only watches `phase-1-build`.
- To deploy without pushing, use the **Deploy** button on the environment page.
- Environment-variable changes are *staged* — they take effect on the next deployment,
  so redeploy after editing them.

---

## 5. Loading the review data (after the first deploy)

The database is the source of truth and everything imported persists in it, so this is a
**one-time** job — later deploys keep the data.

The real workbooks contain genuine purchase orders, costs and invoice values and are
deliberately kept **out of git** (see the root `.gitignore`). So there is no seed file to
point at, and two routes in:

### Option A — the artisan command (preferred)

Put the file somewhere with a temporary link (a signed S3 URL, a Drive/Dropbox direct
link — anything reachable over https), then use **Environment → Commands**:

```bash
php artisan operon:import-master "https://…/OperON_Master_Merged.xlsx"
```

Load the master sheet **first** — it is the catalog every other upload joins onto. A PO
loaded before it sits there with unmatched identifiers until the master arrives.

Then any of the other files, by type:

```bash
php artisan operon:import amazon_po_bulk        "https://…/POItemExport_2026-08-03.xls"
php artisan operon:import amazon_interim_packing "https://…/PACKING LIST … Interim.xlsx"
php artisan operon:import amazon_final_packing   "https://…/PACKING LIST … Final.xlsx"
php artisan operon:import amazon_cancellations   "https://…/Cancelled items_02.08.xlsx"
php artisan operon:import noon_po                "https://…/Al Samha NOONAUH01G … .xlsx"
php artisan operon:import noon_final_picking     "https://…/… - Final.xlsx"
php artisan operon:import amazon_sellout         "https://…/Sales_ASIN_… .xlsx"
php artisan operon:import amazon_inventory       "https://…/Inventory_ASIN_… .xlsx"
php artisan operon:import amazon_dfs             "https://…/DFS Sales_… .xlsx"
php artisan operon:import amazon_dfs_inventory   "https://…/amazon_df_inv_bulk_… .csv"
php artisan operon:import noon_sellout           "https://…/Noon Sell out_… .xlsx"
```

A local path works too (`php artisan operon:import master_sheet ./file.xlsx`), which is
how you reload a Codespace database. Run `php artisan operon:import x x` to print the
full list of type names.

These are not a second import path: the command builds the same upload the browser would
and hands it to the same service, so a file gets the same validation, the same parser,
the same audit row and the same duplicate warning as a UI upload.

### Option B — the Uploads screen

Sign in as the Admin from `ADMIN_EMAIL` → **Uploads** → pick the file type from the
dropdown → upload. Same order: master sheet first, then the M3 / M8 / M9 files. This
needs no links and no shell, and it is the route the team will actually use day to day.

---

## 6. Uploaded files and the ephemeral filesystem

Laravel Cloud's container filesystem is **reset by every deployment**, and each replica
has its own. That is fine, and by design here:

- **Parsed records live in MySQL** and are the source of truth. Deploys do not touch them.
- **Raw workbooks are transient.** The original file is kept only so a rejected upload can
  be re-downloaded and inspected; after a redeploy that download returns a plain 404
  saying the imported data is unaffected, rather than an error.

If you want the originals to survive, attach a **Laravel Cloud object storage** bucket,
set `OPERON_UPLOADS_DISK=s3` and fill in the `AWS_*` variables. Nothing else changes —
the importers are handed a local temp copy whichever disk the file came from.

Sessions, cache and queue all use the **database** for the same reason; a `file` driver
would sign users out on every deploy.

---

## 7. Security checklist for this environment

- [ ] `APP_DEBUG=false` and `APP_ENV=production`
- [ ] `ADMIN_PASSWORD` is random, ≥12 characters, and set only in Laravel Cloud
- [ ] `MONEY_PIN` is not `1234` — the admin bootstrap **fails the deploy** if it is
- [ ] Demo users are absent (they are skipped automatically when `APP_ENV=production` —
      confirm by checking the users list)
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] Consider **HTTP basic auth** (*General Settings → Security*) in front of the whole
      staging environment, so the site is not publicly reachable at all
- [ ] Nothing secret is in the repository: `.env` is git-ignored, `.env.example` ships
      empty values, and every `.xlsx` / `.xls` / `.csv` is ignored repo-wide

Money stays gated the same way it does locally: an Admin-only permission to reach the
screen, and the PIN to open it, re-entered after 15 idle minutes.

---

## 8. Local development is unchanged

```bash
cd backend
cp .env.local.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
composer dev
```

SQLite, no services, and `db:seed` still creates the five demo logins outside production.

**One caveat worth carrying into every PR:** SQLite is much more forgiving than the
managed MySQL. It ignores column length limits and does not enforce `ONLY_FULL_GROUP_BY`,
so an import or a query that passes locally can still fail in production. Raw SQL and any
column holding text out of a spreadsheet are the two places that differ.
