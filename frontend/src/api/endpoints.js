/**
 * API CONTRACT — what the Laravel backend needs to expose for this frontend
 * to work with real data. None of these controllers exist yet in `backend/`
 * (only models + migrations do), so build these routes/controllers next.
 *
 * Auth (Sanctum SPA cookie flow):
 *   GET  /sanctum/csrf-cookie              -> 204, sets XSRF-TOKEN cookie
 *   POST /api/login   { email, password }
 *        -> 200 { user: { id, name, email, role, role_slug, roleLabel, avatar_initials } }
 *        -> 422 { message, errors } on bad credentials
 *        -> 403 { message } when the account is deactivated
 *        Email is globally unique. The controller resolves the frontend
 *        role code (PRG/PL/RES/RA/FIN) from users.role_id -> roles.slug,
 *        which is the user's primary role and decides the dashboard.
 *        Per-project permissions still come from project_user.role_id.
 *   POST /api/logout                        -> 204
 *   GET  /api/me                            -> 200 { user } | 401
 *
 * Dashboards (role-scoped, server decides what the caller may see):
 *   GET /api/dashboard/program              -> Program Leader summary
 *   GET /api/dashboard/project-leader       -> Project Leader summary
 *   GET /api/dashboard/researcher           -> Researcher summary
 *   GET /api/dashboard/research-assistant   -> RA summary
 *
 * Projects:
 *   GET  /api/projects                      -> list (scoped to role)
 *   GET  /api/projects/:id                  -> detail incl. lib_categories rollups
 *   POST /api/projects                      -> create
 *   PUT  /api/projects/:id                  -> update
 *
 * Expenses:
 *   GET  /api/projects/:id/expenses
 *   POST /api/projects/:id/expenses         -> { lib_category_id, description, amount, expense_date, reference_no, attachment }
 *   PATCH /api/expenses/:id/verify
 *   PATCH /api/expenses/:id/reject          -> { reason }
 *
 * Payroll:
 *   GET  /api/projects/:id/payroll?month=YYYY-MM
 *   POST /api/projects/:id/payroll/compute  -> { month }
 *   PATCH /api/payroll/:id/approve
 *
 * Reports:
 *   GET /api/projects/:id/reports/:type     -> type = utilization | ledger | payroll-register | monthly-summary
 *       returns a signed PDF URL or streams the file
 *
 * Audit trail:
 *   GET /api/audit?project_id=&action=&type=&date=
 *
 * Users & roles:
 *   GET  /api/projects/:id/team
 *   POST /api/projects/:id/team              -> { user_id, role_id }
 *   GET  /api/roles                          -> role catalogue + capability matrix
 *
 * All list endpoints accept `page` / `per_page` and return Laravel's default
 * paginator shape ({ data, meta, links }).
 */

export const ENDPOINTS = {
  csrf: '/sanctum/csrf-cookie',
  login: '/api/login',
  register: '/api/register',
  logout: '/api/logout',
  me: '/api/me',
  forgotPassword: '/api/forgot-password',
  resetPassword: '/api/reset-password',
  dashboard: (role) => `/api/dashboard/${role}`,
  programLeaderDashboard: (projectId) =>
    `/api/dashboard/program-leader${projectId ? `?project_id=${encodeURIComponent(projectId)}` : ''}`,
  projectTranches: (projectId) => `/api/projects/${projectId}/tranches`,
  projectBurnRate: (projectId) => `/api/projects/${projectId}/burn-rate`,
  projectCompliance: (projectId) => `/api/projects/${projectId}/compliance`,
  approvals: '/api/approvals',
  approveApproval: (id) => `/api/approvals/${id}/approve`,
  rejectApproval: (id) => `/api/approvals/${id}/reject`,
  projects: '/api/projects',
  project: (id) => `/api/projects/${id}`,
  expenses: (projectId) => `/api/projects/${projectId}/expenses`,
  verifyExpense: (id) => `/api/expenses/${id}/verify`,
  rejectExpense: (id) => `/api/expenses/${id}/reject`,
  payroll: (projectId) => `/api/projects/${projectId}/payroll`,
  computePayroll: (projectId) => `/api/projects/${projectId}/payroll/compute`,
  approvePayroll: (id) => `/api/payroll/${id}/approve`,
  report: (projectId, type) => `/api/projects/${projectId}/reports/${type}`,
  audit: '/api/audit',
  team: (projectId) => `/api/projects/${projectId}/team`,
  roles: '/api/roles',
};
