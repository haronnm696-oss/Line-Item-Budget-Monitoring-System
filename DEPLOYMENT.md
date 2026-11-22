# Deployment: Vercel (frontend) + Render (backend) + Aiven MySQL

This project deploys as a **same-origin** app:

```text
Browser
  -> Vercel (React/Vite)
       -> /api/* and /sanctum/* rewritten to Render
            -> Laravel 8 (Docker) on Render
                 -> Aiven MySQL (free tier)
```

The Vercel proxy keeps Sanctum cookies first-party, avoiding cross-site cookie
blocking in modern browsers.

## 1. Free database on Aiven

1. Create an Aiven account and a **MySQL** service on the **Free** plan.
2. Pick the region closest to your Render region (Singapore is a good default
   for the Philippines).
3. Create a database named `lib_monitoring_system`.
4. Create an app user with rights on that database, or use the service's admin
   user for the first deployment.
5. Download the service CA certificate (`ca.pem`).
6. Import the dump:

```bash
mysql --host=<AIVEN_HOST> --port=<AIVEN_PORT> \
  --user=<AIVEN_USER> --password \
  --ssl-mode=VERIFY_CA --ssl-ca=ca.pem \
  < backups/lib_monitoring_system_20261026_175634.sql
```

The dump contains `CREATE DATABASE` and `USE` statements. If the Aiven user
cannot create databases, create `lib_monitoring_system` in the Aiven console
first, then remove those two lines before importing.

**Important:** Aiven free MySQL has `sql_require_primary_key = 1` by default,
and this dump's `password_resets` table has no primary key, so a plain import
aborts partway. Run the import with that check disabled:

```bash
{ echo "SET SESSION sql_require_primary_key = 0;"; cat backups/lib_monitoring_system_20261026_175634.sql; } \
  | mysql --host=<AIVEN_HOST> --port=<AIVEN_PORT> --user=<AIVEN_USER> --password \
  --ssl-mode=VERIFY_CA --ssl-ca=ca.pem
```

If you use the MariaDB client instead of MySQL 8, swap `--ssl-mode=VERIFY_CA`
for `--ssl-verify-server-cert`.

7. Verify the import. Expect **16 tables**:

```sql
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = 'lib_monitoring_system';
SELECT table_name FROM information_schema.tables
WHERE table_schema = 'lib_monitoring_system' ORDER BY table_name;
```

If fewer than 16 tables appear, the import stopped at `password_resets` and
the container will not boot — repeat the import with `sql_require_primary_key
= 0` before deploying.

## 2. Render backend

Create a **Web Service** from the GitHub repository.

| Setting | Value |
|---|---|
| Runtime | Docker |
| Root directory | `backend` |
| Dockerfile | `Dockerfile` |
| Branch | `main` (or your deployment branch) |
| Instance type | Free |
| Health check path | `/up` |

Set these environment variables in Render:

```text
APP_NAME=LIB Monitoring System
APP_ENV=production
APP_KEY=base64:<generate a fresh key>
APP_DEBUG=false
APP_URL=https://<your-app>.vercel.app

LOG_CHANNEL=stderr
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=<aiven-host>
DB_PORT=<aiven-port>
DB_DATABASE=lib_monitoring_system
DB_USERNAME=<aiven-user>
DB_PASSWORD=<aiven-password>

# Aiven TLS. Optional: a CA certificate is already bundled with the image.
# Override it by pasting the base64-encoded ca.pem here.
AIVEN_CA_CERT_BASE64=<base64 of ca.pem>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
# Do not set SESSION_DOMAIN. The cookie must be host-only for Vercel.

SANCTUM_STATEFUL_DOMAINS=<your-app>.vercel.app,lib-monitoring-api.onrender.com
CORS_ALLOWED_ORIGINS=https://<your-app>.vercel.app

CACHE_DRIVER=file
FILESYSTEM_DRIVER=local
QUEUE_CONNECTION=sync

# Uploaded documents (receipts, MOA, Special Orders, quotations) are stored in
# the database (document_contents), so they survive Render's ephemeral disk.
DOCUMENTS_DISK=database

# Implementing agency printed on R&D Form B when a project leaves its own
# "Implementing Agency" blank. Funder names come from the funders list.
IMPLEMENTING_AGENCY=MSU

# Email (password reset, registration review, approval notifications)
# through Brevo SMTP relay.
FRONTEND_URL=https://<your-app>.vercel.app
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<Brevo SMTP login>
MAIL_PASSWORD=<Brevo SMTP key>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<a sender verified in Brevo>
MAIL_FROM_NAME="LIB Monitoring System"
```

Brevo: create an SMTP key under **SMTP & API → SMTP**, use the SMTP login
shown there as `MAIL_USERNAME`, and verify the sender address (or domain)
under **Senders, Domains & Dedicated IPs** before using it as
`MAIL_FROM_ADDRESS`. Mail from an unverified sender is rejected.

### Testing password-reset mail locally

Set `MAIL_MAILER=log` in `backend/.env`, request a reset, then open
`backend/storage/logs/laravel.log` and copy the `/reset-password?token=...`
link. This is local-only: log mode writes the reset link to disk, so use SMTP
in production and never commit real mail credentials.

Generate `APP_KEY` locally:

```bash
php artisan key:generate --show
```

Encode the Aiven CA certificate:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("ca.pem"))
```

Or on bash:

```bash
base64 -w 0 ca.pem
```

The container entrypoint will:

- Bind Apache to Render's `$PORT`.
- Resolve a usable Aiven CA certificate (from `MYSQL_ATTR_SSL_CA`, the decoded
  `AIVEN_CA_CERT_BASE64`, or the certificate bundled with the image).
- Run `php artisan migrate --force` (with a short retry loop for Aiven idle
  power-off), creating the `sessions` table.
- Start Apache.

Because the entrypoint caches Laravel configuration, changing an environment
variable in Render requires a redeploy or restart.

After deployment, verify:

```text
https://<backend>.onrender.com/          -> 200
https://<backend>.onrender.com/api/me    -> 401 JSON
```

## 3. Vercel frontend

Import the same repository into Vercel.

| Setting | Value |
|---|---|
| Root directory | `frontend` |
| Framework | Vite |
| Build command | `npm run build` |
| Output directory | `dist` |
| Install command | `npm ci` |
| Node.js | 22.x |

Environment variables:

```text
VITE_API_URL=/
VITE_USE_MOCK=false
```

`VITE_API_URL=/` makes Axios call `/api/...` and `/sanctum/...` on the Vercel
domain. Vercel rewrites those paths to Render using `frontend/vercel.json`.

If your Render service is not named `lib-monitoring-api`, update the three
`destination` URLs in `frontend/vercel.json`.

## 4. Smoke test

1. Open `https://<your-app>.vercel.app/login`.
2. Sign in with a seeded account.
3. Refresh the page and confirm the session survives.
4. Open a dashboard.
5. Download a CSV report.
6. Sign out.
7. In browser devtools, confirm `laravel_session` and `XSRF-TOKEN` belong to
   the Vercel domain, are `Secure`, and use `SameSite=Lax`.

## 5. Release checklist

Work through this list for every release that reaches production.

### 5.1 Back up the production database first

Use the **MySQL 8 client** (`mysqldump`, not the MariaDB one). Write the dump
**outside the repository** so it can never be committed:

```bash
mkdir -p ~/lib-backups
mysqldump --host=<AIVEN_HOST> --port=<AIVEN_PORT> --user=<AIVEN_USER> --password \
  --ssl-mode=VERIFY_CA --ssl-ca=ca.pem \
  --single-transaction --routines --triggers --set-gtid-purged=OFF \
  --hex-blob --max-allowed-packet=64M \
  --databases lib_monitoring_system \
  > ~/lib-backups/lib_monitoring_system_$(date +%Y%m%d_%H%M%S).sql
```

- `--hex-blob` is required: uploaded documents are stored as binary in
  `document_contents.content`. Without it, the binary content can be
  corrupted in the dump.
- Check the dump: it is not empty, and its last line is
  `-- Dump completed on …`.
- To restore, use the import in section 1 (with
  `SET SESSION sql_require_primary_key = 0;`) and add
  `--max-allowed-packet=64M` to the `mysql` command.

### 5.2 Check Aiven `max_allowed_packet`

Each document is written to the database as one row of up to 5 MB, and
the hex-encoded dump rows are about twice that size:

```sql
SHOW VARIABLES LIKE 'max_allowed_packet';
```

The value must be at least `33554432` (32 MB). If it is lower, raise
`mysql.max_allowed_packet` under the service's Advanced configuration in the
Aiven console before you deploy.

### 5.3 Environment variables (Render)

Settings in use besides the database, session and CORS values in section 2:

| Variable | Value | Notes |
|---|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Brevo SMTP relay (section 2) | Password reset, registration review and notification emails. `FRONTEND_URL` builds the links in them. |
| `IMPLEMENTING_AGENCY` | `MSU` (default) | Printed on R&D Form B when a project's own Implementing Agency field is blank. |
| `DOCUMENTS_DISK` | `database` | Documents are stored in `document_contents`, so they survive Render's ephemeral filesystem. |
| `RUN_DEMO_ASSIGN` | `1` only for the release that needs it | Runs the idempotent `DemoProjectAssignmentsSeeder` on boot. |
| `RUN_DOCUMENT_IMPORT` | `1` only for the release that needs it | Copies document files from the old disk into the database on boot (idempotent). |

Payroll has no environment settings. Delete any `PAYROLL_*` variable still
set on Render (or in a local `backend/.env`); nothing reads them.

### 5.3.1 Migrations in this release

The entrypoint runs them on boot (`php artisan migrate --force`). All are
additive: they add tables or nullable/defaulted columns and never drop or
rename anything. Added since the previous release notes (2026-11-20):

| Migration | What it does |
|---|---|
| `2026_11_27_000001_add_moa_fields_to_projects` | Adds the MOA number and date, the R&D committee approval date and the fund source (printed on Form B) to `projects`. |
| `2026_11_28_000001_add_implementing_agency_name_to_projects` | Adds the optional per-project Implementing Agency printed on Form B (blank = `IMPLEMENTING_AGENCY`). |
| `2026_11_29_000001_restore_money_column_defaults` | Restores `DEFAULT 0` on NOT NULL money columns that an earlier `MODIFY` dropped (strict-mode error 1364 on New project). |
| `2026_11_30_000001_add_items_and_document_numbers_to_purchase_requests` | Creates `purchase_request_items` (multi-item purchase requests) and adds the PR, SAI and ALOBS numbers and dates to `purchase_requests`. Backfills one item for every existing request. |
| `2026_12_01_000001_add_expense_id_to_purchase_request_items` | Links each purchase request item to the expense that pays it. Backfills existing links. |
| `2026_12_02_000001_add_purchase_request_signatories_to_projects` | Adds the Requested by position and the Noted by / Approved by names and positions printed on purchase requests. |

After the deploy, `SELECT COUNT(*) FROM purchase_requests pr WHERE NOT EXISTS
(SELECT 1 FROM purchase_request_items i WHERE i.purchase_request_id = pr.id);`
should return 0 (every request has its items).

### 5.4 Post-deploy checks

1. **Render logs, `[demo-assign]`** (only if `RUN_DEMO_ASSIGN=1`): expect
   `[demo-assign] start` followed by `[demo-assign] done`. If you see
   `[demo-assign] FAILED`, read the lines above it. The app still starts.
2. **Render logs, `[document-import]`** (only if `RUN_DOCUMENT_IMPORT=1`):
   expect `[document-import] start` and
   `[document-import] done imported=<n> skipped=<n> missing=<n>`. If
   `missing` is above 0, those documents had no file on the old disk and are
   marked as missing. If you see `[document-import] FAILED`, investigate
   before removing the variable.
3. **MEDIUMBLOB check.** Run this on Aiven:

   ```sql
   SELECT DATA_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = 'lib_monitoring_system'
     AND TABLE_NAME = 'document_contents' AND COLUMN_NAME = 'content';
   ```

   Expect `mediumblob`. A plain `blob` caps files at 64 KB.
4. **A 5 MB upload through Vercel.** Sign in at
   `https://<your-app>.vercel.app` (not the Render URL). Upload a file just
   under the 5 MB limit to a record, then download it again and confirm that
   the size matches. This exercises the full path:
   Vercel rewrite → Render → PHP upload limits → MySQL packet size →
   MEDIUMBLOB. A 413 or a timeout here means one of those limits is too low.
5. Run the smoke test in section 4.

### 5.5 Remove the one-time variables afterwards

Once the logs show `[demo-assign] done` and `[document-import] done`, **delete
`RUN_DEMO_ASSIGN` and `RUN_DOCUMENT_IMPORT`** from the Render environment and
redeploy. Otherwise they run again on every boot and slow down each cold
start. Confirm that the next boot log has no `[demo-assign]` or
`[document-import]` lines.

## Known free-tier limits

- Render Free sleeps after 15 minutes idle. The first request can take about a
  minute; upgrading the backend to Render Starter removes the sleep.
- Aiven Free may power off after inactivity; power it back on in the Aiven
  console before redeploying. `max_connections` is capped at 76, so keep the
  worker count low (the shipped `mpm_prefork.conf` uses 15 workers).
- For local testing, `MAIL_MAILER=log` writes the reset link to
  `storage/logs/laravel.log`. Production should use the SMTP settings above.
- Render's filesystem is ephemeral. Uploaded documents are safe because
  `DOCUMENTS_DISK=database` keeps them in MySQL; anything written to
  `storage/` on the container (logs, cached files) is lost on redeploy.
- **Cookie domain note:** `SESSION_DOMAIN` must stay unset so the session cookie
  is host-only for the Vercel domain. Do not set it to `.vercel.app` (a public
  suffix — browsers reject it) or `localhost`.
- **Preview deployments:** `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS`
  list only the production Vercel domain by default. Vercel preview URLs will
  get 401s until you add the preview pattern or a stable custom domain to those
  env vars.
- **TrustProxies:** `$proxies = '*'` trusts all forwarded headers. This is
  Render's documented Laravel setting; impact is limited to `X-Forwarded-For`
  (throttling, logs, audit) and host-based URL generation, since `forceScheme`
  pins HTTPS. Narrow this if Render publishes fixed proxy IP ranges.
- PHP 8.1 and Laravel 8 are EOL. This free-tier setup works, but plan an
  upgrade to PHP 8.3 + Laravel 10/11 for security support.
- `composer.json` now pins `laravel/framework ^8.83`. Before the first deploy,
  run `composer update --lock` (requires Composer) so the committed lock file
  references a stable release rather than a GitHub dev commit.

## Secrets

Never commit:


- `backend/.env`
- `backups/`
- the SQL dump
- Aiven passwords
- the base64 CA secret

The repository root `.gitignore` already excludes these paths.
