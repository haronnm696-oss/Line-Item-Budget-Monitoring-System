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
- `ProjectFinancialService` is the single source of truth for budget, spent, remaining, utilization, and receipt compliance. Every screen must use it so numbers never drift. "Spent" = verified expenses only; payroll is excluded from LIB utilization.
- `ApprovalRequest` (types: disbursement, budget_realignment, payroll, liquidation) implements maker-checker: a user cannot approve a request they created. New requests must have a `justification` (enforced in the model's `creating` hook); legacy rows may have NULL justification and must still display.
- Audit logging goes through `AuditTrail::log()` / `AuditTrail::record()`.
- Migrations must be additive — never drop/rename columns or delete data (production data on Aiven). Money columns are `decimal(15,2)`.

### Frontend data layer
- `src/api/endpoints.js` documents the API contract; `src/api/http.js` provides `getWithFallback`/`mutateWithFallback`, which return mock data from `src/data/` when `VITE_USE_MOCK=true` or when the endpoint returns 404/501. Missing backend endpoints therefore fail silently into mock data — check this when debugging "wrong" numbers.
- Auth state lives in `src/context/AuthContext.jsx`; `ProtectedRoute` gates authentication, `RoleRoute` gates role.

### Deployment (see `DEPLOYMENT.md`)
- Same-origin setup: Vercel serves the frontend and rewrites `/api/*`, `/sanctum/*`, `/storage/*` to the Render backend (`frontend/vercel.json`), keeping Sanctum cookies first-party. Production uses `VITE_API_URL=/`.
- Backend runs as a Docker image on Render (`backend/Dockerfile`, `backend/docker/entrypoint.sh`): binds Apache to `$PORT`, resolves the Aiven CA cert, runs `php artisan migrate --force`, caches config (env changes need a redeploy). Health check `/up`.
- Aiven MySQL has `sql_require_primary_key = 1`; SQL dumps in `backups/` need it disabled on import.

## Conventions

- Implementation plans live in `docs/superpowers/plans/` (dated). Read the relevant one before working on a feature branch; they carry constraints such as the Program Leader UI design tokens (brand `#6F1D2A`, page `#FAF7F2`, etc.), 44px minimum control height, the focus outline style, and "status is text plus icon, never color alone".
- Tests use SQLite in memory, so avoid MySQL-only SQL in queries that tests cover.
