# Demo Seed Rebuild and Auth Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the demo dataset internally consistent across Dashboard, Projects, Reports, and the Program Leader dashboard, remove the unused Google sign-in UI, and make public registration require Program Leader approval.

**Architecture:** One `ProjectFinancialService` owns budget/spent/remaining/utilization/receipt-compliance calculations. Both existing dashboard services and the report export use it. A single `DemoDatasetSeeder` writes people, projects, line items, tranches, expenses, approvals, liquidations, reports, and audit rows with Carbon-relative dates. Registration writes pending, role-less users using existing `role_id`/`is_active` columns; no schema changes.

**Tech Stack:** Laravel 8.83, Sanctum, MySQL/SQLite, React 19, Vite, PHPUnit.

**Spec:** User brief approved 2026-11-07 (chat).

## Global Constraints

- Do not drop tables or add/modify migrations or schema. Use existing columns only.
- Only touch seeders, calculation/services, auth code, the named React pages, and tests.
- Spent = verified expenses only. Budget = `total_approved_budget` (fallback line-item total only when zero).
- Receipt compliance is `null` when a project has zero expenses.
- Completed/past-end projects are never "At risk" for under-spending.
- Maker cannot verify/approve a record they created.
- Seeder files are UTF-8; connection remains `utf8mb4`.
- `migrate:fresh --seed` is allowed on the local demo DB only; production requires separate approval.

## Review Focus

- A project with zero expenses must render receipt compliance as "—", not 0%.
- A Program Leader must not be able to approve a request they created.
- Registration must not create an authenticated session or assign a role.
- A pending account must not be able to log in until approved.
- A completed, fully liquidated project must never show "At risk" for under-spending.

---

### Task 1: Shared financial calculations

**Files:**
- Create: `backend/app/Services/ProjectFinancialService.php`
- Modify: `backend/app/Services/DashboardService.php`
- Modify: `backend/app/Services/ProgramDashboardService.php`
- Modify: `backend/app/Models/Project.php`
- Modify: `backend/app/Models/LibCategory.php`
- Test: `backend/tests/Feature/FinancialConsistencyTest.php`

**Interfaces:**
- `ProjectFinancialService::financials(Project $project): array` returns `budget`, `spent`, `remaining`, `utilization_pct`, `expenses_count`, `receipts_count`, `receipt_compliance_pct`.
- `ProjectFinancialService::categoryRows(Project $project): array` uses verified expenses only.
- `ProjectFinancialService::compliance(Project $project): array` returns `compliance_pct => null` when no expenses.
- `ProjectFinancialService::derivedStatus(Project $project): string`.

- [ ] Write consistency test against `/api/dashboard/program-leader`, `/api/projects`, `/api/projects/{id}`, `/api/projects/{id}/reports/utilization`, `/api/program/overview`.
- [ ] Implement service and refactor both dashboard services/models to use it.
- [ ] Run the test and `php artisan test --filter=FinancialConsistency`.

### Task 2: Rebuild demo dataset

**Files:**
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Rewrite: `backend/database/seeders/DemoUsersSeeder.php`
- Create: `backend/database/seeders/DemoDatasetSeeder.php`
- Remove calls: `ProjectsSeeder`, `LibCategoriesSeeder`, `DashboardDemoSeeder`, `ProgramDashboardSeeder`

**Interfaces:**
- `DemoUsersSeeder` produces 8 accounts: program leader, 4 project leaders, RA, finance, researcher.
- `DemoDatasetSeeder` produces 4 `MOST-RD-YYYY-NNN` projects, 4 tranches each, 8–15 expenses per active project, 3 pending approvals (fund release, realignment, liquidation), and audit rows.
- All dates use `now()->subMonths()`/`subDays()`.

- [ ] Write accounts and projects with derived statuses.
- [ ] Write line items whose totals equal each approved budget.
- [ ] Write tranches, expenses, liquidations, reports, approvals, and audit rows.
- [ ] Run the seeder twice to prove idempotency (without fresh).

### Task 3: Auth cleanup and pending registration

**Files:**
- Modify: `backend/app/Http/Controllers/AuthController.php`
- Modify: `backend/app/Http/Controllers/ApprovalController.php`
- Modify: `backend/app/Services/ProgramDashboardService.php`
- Create: `backend/app/Http/Controllers/UserApprovalController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/RegistrationTest.php`
- Create: `backend/tests/Feature/LoginTest.php`
- Create: `backend/tests/Feature/MakerCheckerTest.php`
- Modify: `frontend/src/pages/Login.jsx`
- Modify: `frontend/src/pages/Register.jsx`
- Modify: `frontend/src/api/auth.js`
- Create: `frontend/src/api/users.js`
- Modify: `frontend/src/pages/Users.jsx`

**Interfaces:**
- `POST /api/register` returns `201 { user, pending: true }` and does not authenticate.
- `GET /api/users/pending`, `PATCH /api/users/{user}/approve { role }`.
- `AuthController::login` rejects `role_id === null` with a pending message.
- `ApprovalController::review` and `ProgramDashboardService::decide` reject `requested_by === actor->id`.

- [ ] Update registration/login backend and add approval endpoints.
- [ ] Remove Google UI and role dropdowns from Login; remove role field from Register.
- [ ] Add pending-registration panel to Users.
- [ ] Add/update tests for pending registration, all-role login, and maker-checker.

### Task 4: Frontend reports consistency

**Files:**
- Modify: `frontend/src/pages/Reports.jsx`
- Modify: `frontend/src/pages/Projects.jsx` only if the API shape requires it.

- [ ] Replace `mockData` summary and utilization sources with `/api/projects` and `/api/projects/{id}`.
- [ ] Keep print/CSV layout and maroon styling.
- [ ] Confirm zero-expense receipt compliance renders "—".

### Task 5: Verification

- [ ] Run `php artisan migrate:fresh --seed` on the local demo DB (approved).
- [ ] Run `php artisan test`.
- [ ] Run `npm run build` in `frontend`.
- [ ] Confirm built bundle contains no Google sign-in strings.
- [ ] Report results and Render commands.
