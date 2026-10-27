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

# Aiven TLS. Paste the base64-encoded ca.pem here.
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
MAIL_MAILER=log
```

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
- Decode the Aiven CA certificate from `AIVEN_CA_CERT_BASE64`.
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

## Known free-tier limits

- Render Free sleeps after 15 minutes idle. The first request can take about a
  minute; upgrading the backend to Render Starter removes the sleep.
- Aiven Free may power off after inactivity; power it back on in the Aiven
  console before redeploying. `max_connections` is capped at 76, so keep the
  worker count low (the shipped `mpm_prefork.conf` uses 15 workers).
- `MAIL_MAILER=log` means password-reset emails are written to Render logs.
  Configure SMTP if real email delivery is required.
- Render's filesystem is ephemeral. Add S3/R2 storage or a paid persistent disk
  before relying on uploaded files.
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
