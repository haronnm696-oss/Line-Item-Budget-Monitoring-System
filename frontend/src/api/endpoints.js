/**
 * API CONTRACT — what this frontend calls. Routes marked (not built yet) do
 * not exist in `backend/routes/api.php`: calls to them fail with a clear
 * error ("Could not load…" / "Could not save…"); there is no sample-data
 * fallback (see api/http.js).
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
 *   GET  /api/projects                      -> list (Program Leader: all; others: assigned projects only)
 *   GET  /api/projects/:id                  -> detail incl. lib_categories rollups and expenses (403 if not assigned)
 *   POST /api/projects                      -> create (not built yet)
 *   PUT  /api/projects/:id                  -> update (not built yet)
 *
 * Expenses (workflow: draft -> submitted -> endorsed "Approved by Project
 * Leader" -> verified, or returned; only verified counts as spent):
 *   GET    /api/projects/:id/expenses?workflow_status=draft|submitted|endorsed|returned|verified
 *   POST   /api/projects/:id/expenses       -> { lib_category_id, description, amount, expense_date, reference_no?, payee? }
 *          creates a DRAFT (RA / FIN of the project)
 *   PATCH  /api/expenses/:id                -> same fields; draft or returned only
 *   DELETE /api/expenses/:id                -> own draft or returned only (soft delete)
 *   POST   /api/expenses/:id/submit         -> RA / FIN; needs at least one receipt document
 *   POST   /api/expenses/:id/endorse        -> Project Leader of the project
 *   POST   /api/expenses/:id/return         -> { reason } Project Leader
 *   POST   /api/expenses/:id/verify         -> FIN of the project (PATCH also accepted)
 *   POST   /api/expenses/:id/reject         -> { reason } FIN; sends it back as returned (PATCH also accepted)
 *   403 = wrong role or maker = checker; 422 = not allowed in the current stage.
 *
 * Documents (PDF / JPG / PNG, up to 10 MB):
 *   GET    /api/expenses/:id/documents
 *   POST   /api/expenses/:id/documents      -> multipart { file, document_type: receipt|invoice|quotation|payroll_file|other }
 *   GET    /api/documents/:id/download      -> the file (project members only)
 *   DELETE /api/documents/:id               -> uploader only, not after verification
 *
 * Notifications (the signed-in user's own):
 *   GET    /api/notifications?page=         -> { notifications: [{ id, title, message, link, read, created_at }], meta: { unread, has_more, ... } }
 *   GET    /api/notifications/unread-count  -> { unread }
 *   PATCH  /api/notifications/:id/read
 *   PATCH  /api/notifications/read-all
 *
 * Payroll (same workflow as expenses; not part of LIB spent):
 *   GET    /api/projects/:id/payroll?month=YYYY-MM
 *          -> { month, month_label, rows, deductionBreakdown, totals, unset_rules, rated_members, ps_line_items }
 *   POST   /api/projects/:id/payroll          -> { month, lib_category_id (PS) } create + compute the month (RA / FIN)
 *   POST   /api/projects/:id/payroll/compute  -> { month } recompute draft / returned rows (RA / FIN)
 *   PATCH  /api/payroll/:id                   -> { basic_rate?, allowance?, lib_category_id? } draft / returned only
 *   POST   /api/payroll/:id/submit | endorse | return { reason } | verify | reject { reason }
 *   GET    /api/projects/:id/pay-rates        (FIN, RA, Program Leader)
 *   PATCH  /api/projects/:id/pay-rates/:userId -> { monthly_rate|null, allowance? } (FIN, Program Leader)
 *   Documents: GET/POST /api/payroll/:id/documents (document_type payroll_file | other)
 *
 * Purchase requests (draft -> submitted -> endorsed, or returned; no Finance step):
 *   GET    /api/projects/:id/purchase-requests?workflow_status=
 *   POST   /api/projects/:id/purchase-requests -> { lib_category_id, description, quantity, estimated_amount }
 *   PATCH  /api/purchase-requests/:id          (draft / returned)
 *   DELETE /api/purchase-requests/:id          (own draft)
 *   POST   /api/purchase-requests/:id/submit (needs a quotation or invoice) | endorse | return { reason }
 *   Documents: GET/POST /api/purchase-requests/:id/documents (quotation | invoice | other)
 *
 * Budget requests (Project Leader -> Program Leader; shown on Program Approvals):
 *   GET    /api/projects/:id/budget-requests
 *   POST   /api/projects/:id/budget-requests  -> { type: fund_release|realignment|liquidation, justification, ... }
 *          fund_release: project_tranche_id; realignment: from_lib_category_id, to_lib_category_id, amount;
 *          liquidation: amount, title?, files[] (multipart, at least one)
 *   Documents: GET/POST /api/approvals/:id/documents (requester, while pending)
 *
 * Balancing query:
 *   GET    /api/projects/:id/balancing?line_item=
 *          -> { line_items: [{ allocated, spent, pending_expenses, pending_purchase_requests, pending, available }], totals }
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
  programOverview: '/api/program/overview',
  programProjects: '/api/program/projects',
  programProjectsExport: '/api/program/projects/export',
  programProject: (id) => `/api/program/projects/${id}`,
  programApprovals: '/api/program/approvals',
  programAudit: '/api/program/audit',
  programDecision: (id) => `/api/program/approvals/${id}/decision`,
  programRegistrations: '/api/program/registrations',
  programRegistrationDecision: (id) => `/api/program/registrations/${id}/decision`,
  programUsers: '/api/program/users',
  programUser: (id) => `/api/program/users/${id}`,
  programUserStatus: (id) => `/api/program/users/${id}/status`,
  programUserRole: (id) => `/api/program/users/${id}/role`,
  programAssignments: '/api/program/assignments',
  programAssignment: (userId, projectId) => `/api/program/assignments/${userId}/${projectId}`,
  projectTranches: (projectId) => `/api/projects/${projectId}/tranches`,
  projectBurnRate: (projectId) => `/api/projects/${projectId}/burn-rate`,
  projectCompliance: (projectId) => `/api/projects/${projectId}/compliance`,
  approvals: '/api/approvals',
  projects: '/api/projects',
  project: (id) => `/api/projects/${id}`,
  expenses: (projectId) => `/api/projects/${projectId}/expenses`,
  expense: (id) => `/api/expenses/${id}`,
  submitExpense: (id) => `/api/expenses/${id}/submit`,
  endorseExpense: (id) => `/api/expenses/${id}/endorse`,
  returnExpense: (id) => `/api/expenses/${id}/return`,
  verifyExpense: (id) => `/api/expenses/${id}/verify`,
  rejectExpense: (id) => `/api/expenses/${id}/reject`,
  expenseDocuments: (id) => `/api/expenses/${id}/documents`,
  documentDownload: (id) => `/api/documents/${id}/download`,
  document: (id) => `/api/documents/${id}`,
  notifications: '/api/notifications',
  notificationsUnread: '/api/notifications/unread-count',
  notificationsReadAll: '/api/notifications/read-all',
  notificationRead: (id) => `/api/notifications/${id}/read`,
  payroll: (projectId) => `/api/projects/${projectId}/payroll`,
  computePayroll: (projectId) => `/api/projects/${projectId}/payroll/compute`,
  payrollEntry: (id) => `/api/payroll/${id}`,
  payrollStep: (id, action) => `/api/payroll/${id}/${action}`,
  payrollDocuments: (id) => `/api/payroll/${id}/documents`,
  payRates: (projectId) => `/api/projects/${projectId}/pay-rates`,
  payRate: (projectId, userId) => `/api/projects/${projectId}/pay-rates/${userId}`,
  purchaseRequests: (projectId) => `/api/projects/${projectId}/purchase-requests`,
  purchaseRequest: (id) => `/api/purchase-requests/${id}`,
  purchaseRequestStep: (id, action) => `/api/purchase-requests/${id}/${action}`,
  purchaseRequestDocuments: (id) => `/api/purchase-requests/${id}/documents`,
  budgetRequests: (projectId) => `/api/projects/${projectId}/budget-requests`,
  approvalDocuments: (id) => `/api/approvals/${id}/documents`,
  balancing: (projectId) => `/api/projects/${projectId}/balancing`,
  report: (projectId, type) => `/api/projects/${projectId}/reports/${type}`,
  audit: '/api/audit',
  team: (projectId) => `/api/projects/${projectId}/team`,
  roles: '/api/roles',
  pendingUsers: '/api/users/pending',
  approveUser: (id) => `/api/users/${id}/approve`,
};
