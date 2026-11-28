# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

LIB (Line-Item Budget) Monitoring System: tracks research project budgets, expenses, tranches, payroll, liquidations, and maker-checker approvals. Monorepo with two independent apps:

- `backend/` — Laravel 8 (PHP ^7.3|^8.0) JSON API, Sanctum SPA cookie auth, MySQL (utf8mb4).
- `frontend/` — React 19 + Vite + React Router 7 + Tailwind 4 / plain CSS tokens, axios.

## Commands

Backend (run from `backend/`):

```bash
composer install
php artisan migrate            # MySQL db `lib_monitoring_system` (see .env.example)
php artisan db:seed            # DemoUsersSeeder + DemoDatasetSeeder
php artisan serve              # http://localhost:8000
php artisan test               # all tests (SQLite :memory:, see phpunit.xml)
php artisan test --filter=MakerCheckerTest
php artisan test --filter=test_a_program_leader_cannot_approve_a_request_they_created
```

Frontend (run from `frontend/`):

```bash
npm install
npm run dev        # Vite on http://localhost:5173
npm run build
npm run lint       # oxlint (.oxlintrc.json: react/rules-of-hooks is an error)
node --test src/lib/authValidation.test.js   # frontend unit tests use node:test, no runner configured
```

Local mail testing: set `MAIL_MAILER=log` in `backend/.env`; reset links land in `backend/storage/logs/laravel.log`.

## Architecture

### Auth and roles
- Sanctum SPA cookie flow: frontend calls `/sanctum/csrf-cookie`, then `POST /api/login`. Auth routes in `backend/routes/api.php` use the `web` middleware plus throttles. `frontend/src/api/client.js` sets `withCredentials`/`withXSRFToken` and emits a `lib:unauthorized` window event on 401.
- Two role layers: `users.role_id` (primary role, decides which dashboard the user lands on) and `project_user.role_id` (per-project permissions). Role slugs: `program-leader`, `project-leader`, `researcher`, `research-assistant`, `finance-admin` — mapped to frontend codes `PRG`/`PL`/`RES`/`RA`/`FIN` by `AuthController`.
- Program Leader-only API routes are grouped under `/api/program/*` with the `role.program-leader` middleware (`EnsureProgramLeader`). On the frontend, `RoleRoute allow={['PRG']}` wraps `ProgramLayout` (`/program-*` pages); other roles use `AppLayout`. `ROUTE_BY_ROLE` in `pages/Dashboard.jsx` maps roles to landing routes.
- Self-registration requires review/approval (`UserApprovalController`, `ProgramUserController::decideRegistration`), with notifications in `app/Notifications/`.

### Backend layering
- Controllers are thin; business logic lives in `app/Services/`.
- `ProjectFinancialService` is the single source of truth for budget, spent, remaining, utilization, and receipt compliance. Every screen must use it so numbers never drift. "Spent" = verified expenses + verified payroll (gross pay, charged to each row's PS line item, funder share); "pending" includes payroll waiting for approval or verification. PS line items with personnel linked (honoraria lines) are paid through Payroll only: expenses on them are refused.
- `ApprovalRequest` (types: disbursement, budget_realignment, payroll, liquidation) implements maker-checker: a user cannot approve a request they created. New requests must have a `justification` (enforced in the model's `creating` hook); legacy rows may have NULL justification and must still display.
- Audit logging goes through `AuditTrail::log()` / `AuditTrail::record()`.
- Migrations must be additive — never drop/rename columns or delete data (production data on Aiven). Money columns are `decimal(15,2)`.
- Production MySQL runs in strict mode: an insert that leaves out a NOT NULL column without a default fails ("1364 Field ... doesn't have a default value"), while SQLite tests can pass. Every new NOT NULL column must have a default or be set explicitly on every insert. A raw `ALTER TABLE ... MODIFY` replaces the whole column definition, so repeat its `DEFAULT` (and `NULL`/`NOT NULL`) or it is silently dropped. `tests/Feature/FormInsertsTest.php` saves each form with only the fields it sends; run it against a scratch MySQL database when changing columns. `DB_CONNECTION=mysql php artisan test` does NOT do this: the `<server>` entries in `phpunit.xml` override shell variables, so the test silently runs on SQLite. Use a separate phpunit config instead: create an empty scratch database, copy `backend/phpunit.xml` to `backend/phpunit.mysql.xml` (same folder so relative paths work; it holds a password, so it is git-ignored and must never be committed), replace its `DB_CONNECTION`/`DB_DATABASE` `<server>` entries with `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` and `DB_DATABASE=<scratch db>`, run `php vendor/bin/phpunit -c phpunit.mysql.xml --filter=FormInsertsTest` from `backend/`, confirm the scratch database now has tables (proof MySQL was used), then drop it. Never point it at the real database: `RefreshDatabase` wipes it.

### Frontend data layer
- `src/api/endpoints.js` documents the API contract and marks routes that are not built yet. `src/api/http.js` provides `apiGet`/`apiWrite` (via `src/lib/apiResult.js`): any failed request rejects with a clear error ("Could not load…" / "Could not save. Please try again."). There is no sample-data fallback; pages show loading, error ("Try again") or empty states (`useApiData`, `LoadState`).
- Route access per role lives in `src/routes/access.js` (`ROUTE_ROLES`); `RoleRoute` redirects other roles to their own dashboard. Pages pick the user's assigned project with `useAssignedProject` (`?project=`). The backend scopes projects too (`EnsureProjectAccess`, `User::accessibleProjectIds()`).
- Auth state lives in `src/context/AuthContext.jsx`; `ProtectedRoute` gates authentication, `RoleRoute` gates role.

### Deployment (see `DEPLOYMENT.md`)
- Same-origin setup: Vercel serves the frontend and rewrites `/api/*`, `/sanctum/*`, `/storage/*` to the Render backend (`frontend/vercel.json`), keeping Sanctum cookies first-party. Production uses `VITE_API_URL=/`.
- Backend runs as a Docker image on Render (`backend/Dockerfile`, `backend/docker/entrypoint.sh`): binds Apache to `$PORT`, resolves the Aiven CA cert, runs `php artisan migrate --force`, caches config (env changes need a redeploy). Health check `/up`.
- Production database is TiDB Cloud Starter (MySQL-compatible, TLS via `DB_SSL=true`); the old Aiven MySQL is powered off. TiDB rejects an `ALTER TABLE` whose `AFTER` names a column added in the same statement, so MySQL connections use `App\Database\MySqlSchemaGrammar` (one `ADD` per statement). The first Program Leader is created with `php artisan lib:create-program-leader`.
- Aiven MySQL had `sql_require_primary_key = 1`; SQL dumps in `backups/` need it disabled on import.

## Conventions

- Implementation plans live in `docs/superpowers/plans/` (dated). Read the relevant one before working on a feature branch; they carry constraints such as the Program Leader UI design tokens (brand `#6F1D2A`, page `#FAF7F2`, etc.), 44px minimum control height, the focus outline style, and "status is text plus icon, never color alone".
- Tests use SQLite in memory, so avoid MySQL-only SQL in queries that tests cover.
- Peso fields accept commas and a leading ₱ ("3,178,376.00"). Every new peso field must be added to the money-field list `App\Support\MoneyInput::FIELDS`, which the `NormalizeMoneyInput` middleware uses to strip them before validation, and validated with `MoneyInput::RULES`. On the frontend, use `components/MoneyInput.jsx` and `lib/moneyInput.js` (`moneyError`, `moneyNumber`), never `Number(text)`.
- Dates are limited to 2000–2100. Every new date field must use the `App\Rules\DateInRange` rule (after `'bail'`, before `'date'`/`'date_format'`). On the frontend, give the input `DATE_BOUNDS` from `lib/dateRange.js` and check it with `dateRangeError`.
