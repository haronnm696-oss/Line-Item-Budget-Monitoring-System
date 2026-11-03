# Program Leader Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded Program Leader dashboard with a real, program-wide API-backed dashboard for all projects, including approvals, forecasts, health, deadlines, and exceptions.

**Architecture:** New Laravel service builds one aggregate payload from eager-loaded Eloquent data and computes all health rules server-side. A thin controller exposes two Program-Leader-only routes. React consumes the payload through the existing axios client and keeps the existing sidebar/layout/tokens.

**Tech Stack:** Laravel 8.83, Sanctum, MySQL/utf8mb4, React 19, Vite, existing axios client.

**Spec:** User task brief + screenshots, approved 2026-11-03.

## Global Constraints

- Do not modify Project Leader, Research Assistant, or Researcher pages.
- New migrations only; no drops or renames of existing columns/tables; no data deletion.
- Money columns are `DECIMAL(15,2)`.
- Database connection charset is `utf8mb4`; new text must save `₱` and en dashes correctly.
- Only `program-leader` may access the new endpoints; all other roles get 403.
- No hardcoded API URLs; use `VITE_API_URL` through `frontend/src/api/client.js`.
- No new UI libraries.
- Run `npm run build`, `php artisan route:list`, `php artisan test` at the end.

## Review Focus

- A non-Program-Leader calling either new endpoint must receive 403, not data.
- Rejecting without remarks must fail validation.
- Approval decisions must be atomic and produce an audit row.
- A project with an overdue tranche must never be Healthy.
- Funder filtering must apply to every section, not only the matrix.

---

### Task 1: Database foundations

**Files:**
- Create: `backend/database/migrations/2026_01_01_000002_create_users_table.php`
- Create: `backend/database/migrations/2026_11_03_000001_widen_money_columns_to_decimal_15_2.php`
- Create: `backend/database/migrations/2026_11_03_000002_create_liquidations_table.php`
- Create: `backend/database/migrations/2026_11_03_000003_create_project_reports_table.php`
- Create: `backend/database/migrations/2026_11_03_000004_add_liquidation_id_to_approval_requests_table.php`

**Interfaces:**
- Produces tables `liquidations`, `project_reports`, `approval_requests.liquidation_id`.
- `users` migration is guarded with `Schema::hasTable`.
- Money widening is MySQL-only; SQLite tests skip it.

- [ ] Add guarded users migration matching the imported schema used by later migrations.
- [ ] Add money-widening migration for `projects`, `lib_categories`, `expenses`, `project_tranches`, `approval_requests`, `payrolls`, `payroll_deductions`, and new money tables.
- [ ] Add `liquidations` and `project_reports` tables with explicit `utf8mb4`.
- [ ] Add nullable `liquidation_id` FK to `approval_requests`.
- [ ] Run `php artisan migrate` locally and confirm no data is lost.

### Task 2: Models and relationships

**Files:**
- Create: `backend/app/Models/Liquidation.php`
- Create: `backend/app/Models/ProjectReport.php`
- Modify: `backend/app/Models/Project.php`
- Modify: `backend/app/Models/ApprovalRequest.php`

**Interfaces:**
- `Project::liquidations(): HasMany`
- `Project::reports(): HasMany`
- `ApprovalRequest::liquidation(): BelongsTo`
- `Liquidation` statuses: `pending|approved|rejected`.
- `ProjectReport` types: `progress|terminal|financial`.

- [ ] Add models with fillable/casts/relationships.
- [ ] Add inverse relationships on Project and ApprovalRequest.

### Task 3: ProgramDashboardService

**Files:**
- Create: `backend/app/Services/ProgramDashboardService.php`
- Test: `backend/tests/Feature/ProgramDashboardTest.php`

**Interfaces:**
- `overview(?string $funder): array`
- `decide(ApprovalRequest $approval, string $decision, ?string $remarks, User $actor): ApprovalRequest`
- Response keys: `meta`, `kpis`, `projects`, `approvals`, `forecast`, `funders`, `deadlines`, `exceptions`.

- [ ] Write failing feature test for health rules and response keys.
- [ ] Implement eager-loaded project aggregation.
- [ ] Implement health rules exactly per brief: At risk if overdue tranche OR spent >= 95 OR gap <= -40; Watch if gap <= -20 OR receipt compliance < 80; else Healthy. Always return `health_reason`.
- [ ] Implement approvals oldest first with `days_waiting`.
- [ ] Implement 4-month forecast with overdue tranches in the current month.
- [ ] Implement funders, deadlines, and three exception groups.
- [ ] Implement transactional decisions with side effects and `AuditTrail::log`.

### Task 4: Controller, middleware, routes

**Files:**
- Create: `backend/app/Http/Controllers/ProgramDashboardController.php`
- Create: `backend/app/Http/Middleware/EnsureProgramLeader.php`
- Modify: `backend/app/Http/Kernel.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- `GET /api/program/overview?funder=`
- `POST /api/program/approvals/{approval}/decision` body `{ decision, remarks }`.

- [ ] Add middleware returning 403 unless `primaryRole.slug === 'program-leader'`.
- [ ] Add thin controller methods.
- [ ] Protect both routes with `auth:sanctum` + `role.program-leader`.
- [ ] Write failing tests for 403 and validation, then make them pass.

### Task 5: Demo seeder

**Files:**
- Create: `backend/database/seeders/ProgramDashboardSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

- [ ] Seed funders and link 4 projects.
- [ ] Seed line items, expenses, tranches, reports, liquidations, and pending approvals using `updateOrCreate`.
- [ ] Include one overdue tranche, one near-depletion project, and one under-spending project.
- [ ] Run `php artisan db:seed --class=ProgramDashboardSeeder` twice to prove idempotency.

### Task 6: React API and route access

**Files:**
- Create: `frontend/src/api/program.js`
- Create: `frontend/src/components/RoleRoute.jsx`
- Modify: `frontend/src/App.jsx`
- Modify: `frontend/src/components/Sidebar.jsx`

- [ ] Add `fetchProgramOverview(funder)` and `decideApproval(id, payload)` using the existing axios client.
- [ ] Add `/program-dashboard` route guarded to `PRG`.
- [ ] Make `/dashboard` render by role without touching other dashboard files.
- [ ] Add a Program-only sidebar entry while keeping the sidebar layout.

### Task 7: Dashboard UI

**Files:**
- Rewrite: `frontend/src/pages/dashboards/ProgramLeaderDashboard.jsx`
- Create: `frontend/src/components/program/ApprovalInbox.jsx`
- Create: `frontend/src/components/program/ForecastChart.jsx`
- Create: `frontend/src/components/program/HealthMatrix.jsx`
- Create: `frontend/src/components/program/FundingSources.jsx`
- Create: `frontend/src/components/program/ReportingCalendar.jsx`
- Create: `frontend/src/components/program/ExceptionsPanel.jsx`
- Create: `frontend/src/components/program/RejectModal.jsx`
- Modify: `frontend/src/styles/global.css`

- [ ] Build header, funder filter, KPI cards, and empty/loading/error states.
- [ ] Build approval inbox with Approve/Reject and remarks modal.
- [ ] Build forecast bars, matrix, funding sources, calendar, exceptions.
- [ ] Match maroon/gold styling and responsive behavior.

### Task 8: Verification and deployment report

- [ ] Run `php artisan migrate` and `php artisan db:seed --class=ProgramDashboardSeeder`.
- [ ] Run `npm run build`.
- [ ] Run `php artisan route:list`.
- [ ] Run `php artisan test`.
- [ ] Report files, endpoints, migrations, env vars, deploy commands, and local test steps.
