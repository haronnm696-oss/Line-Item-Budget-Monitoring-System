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
 *   GET  /api/projects/:id                  -> detail incl. lib_categories rollups and expenses (403 if not assigned);
 *          payroll_rates_label (PAYROLL_RATES_LABEL or null, printed on the Payroll register)
 *   POST /api/projects                      -> create (not built yet)
 *   PUT  /api/projects/:id                  -> update (not built yet)
 *
 * Expenses (workflow: draft -> submitted -> endorsed "Approved by Project
 * Leader" -> verified, or returned; only verified counts as spent):
 *   GET    /api/projects/:id/expenses?workflow_status=draft|submitted|endorsed|returned|verified
 *          rows carry can_resubmit (returned and recorded by this user)
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
 * Documents (PDF / JPG / PNG, up to 5 MB; an exact duplicate on the same record is refused).
 * Bytes are stored per DOCUMENTS_DISK (default: the database) and only ever
 * streamed by the download route; lists carry `available: false` when the
 * file is missing ("File not available").
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
 *          -> { month, month_label, rows, deductionBreakdown, totals, unset_rules, rates_label, rated_members, ps_line_items }
 *   POST   /api/projects/:id/payroll          -> { month, lib_category_id (PS) } create + compute the month (RA / FIN)
 *   POST   /api/projects/:id/payroll/compute  -> { month } recompute draft / returned rows (RA / FIN)
 *   PATCH  /api/payroll/:id                   -> { basic_rate?, allowance?, lib_category_id? } while the batch is draft / returned
 *   POST   /api/payroll-batches/:id/submit | endorse | return { reason } | verify | reject { reason }
 *          (the whole month at once; GET payroll returns the month's batch)
 *   GET    /api/projects/:id/pay-rates        (FIN, RA, Program Leader)
 *   PATCH  /api/projects/:id/pay-rates/:userId -> { monthly_rate|null, allowance? } (FIN, Program Leader)
 *   Documents: GET/POST /api/payroll-batches/:id/documents (document_type payroll_file | other)
 *
 * Purchase requests (draft -> submitted -> endorsed, or returned; no Finance step;
 * an approved request ends as fulfilled or cancelled):
 *   GET    /api/projects/:id/purchase-requests?workflow_status=
 *   POST   /api/projects/:id/purchase-requests -> { lib_category_id, description, quantity, estimated_amount, supplier? }
 *   PATCH  /api/purchase-requests/:id          (draft / returned)
 *   DELETE /api/purchase-requests/:id          (own draft)
 *   POST   /api/purchase-requests/:id/submit (needs a quotation or invoice) | endorse | return { reason }
 *   POST   /api/purchase-requests/:id/cancel  -> { reason } approved and unfulfilled; Project Leader or requester
 *   Fulfil: POST/PATCH an expense with purchase_request_id (approved request, same project and line item)
 *   Documents: GET/POST /api/purchase-requests/:id/documents (quotation | invoice | other)
 *
 * Budget requests (Project Leader -> Program Leader; shown on Program Approvals):
 *   GET    /api/projects/:id/budget-requests
 *   POST   /api/projects/:id/budget-requests  -> { type: fund_release|realignment|liquidation, justification, ... }
 *   POST   /api/budget-requests/:id/resubmit   -> a returned request, edited (same fields; type fixed; files[], remove_document_ids[])
 *   POST   /api/budget-requests/:id/withdraw   -> a returned request, closed by its maker
 *   Row status: pending | approved | returned | withdrawn (Program Leader decision: approved | returned + remarks)
 *          fund_release: project_tranche_id; realignment: from_lib_category_id, to_lib_category_id, amount;
 *          liquidation: amount, title?, files[] (multipart, at least one)
 *   Documents: GET/POST /api/approvals/:id/documents (requester, while pending)
 *
 * Balancing query:
 *   GET    /api/projects/:id/balancing?line_item=
 *          -> { line_items: [{ allocated, spent, pending_expenses, pending_purchase_requests, pending, available }], totals }
 *
 * Reports:
 *   GET /api/projects/:id/reports/:type     -> CSV; type = form-b | form-c | utilization | liquidation | payroll-register
 *       (percent cells are plain numbers, never "<0.1")
 *   GET /api/projects/:id/payroll-register?period=all|year|90d&month=YYYY-MM
 *       -> { project, period, period_label, rates_label, deduction_types,
 *            months: [{ month, month_label, batch_id, verified_at, verified_by, rows, totals }], totals }
 *       VERIFIED payroll batches only; CSV: /reports/payroll-register with the same params.
 *       Any user of the project (and the Program Leader); 403 otherwise.
 *
 * Audit trail:
 *   GET /api/audit?project_id=&action=&type=&date=
 *
 * Users & roles:
 *   GET  /api/projects/:id/team
 *   POST /api/projects/:id/team              -> { user_id, role_id }
 *   GET  /api/roles                          -> role catalogue + capability matrix
 *
 * Project Leader pages (project-leader of the project; 403 otherwise):
 *   GET /api/projects/:id/leader-overview   -> { project, kpis, review, alerts, monthly_expenses, category_summary, transactions, requests }
 *   GET /api/projects/:id/review-queue?tab=waiting|returned|approved&type=expense|payroll|purchase_request
 *                                           -> { counts: { waiting, returned, approved }, items }
 *   GET /api/projects/:id/review-queue/:type/:id -> { item } (any stage)
 *   GET  /api/projects/:id/leader-badges     -> { submissions (= To review), requests (decisions not seen) }
 *   POST /api/projects/:id/budget-requests/seen -> same; marks the decisions seen (My requests opened)
 *   A Project Leader may also record expenses (POST /projects/:id/expenses) and edit, attach
 *   receipts to and submit their own; their submit skips the approval step (-> endorsed,
 *   "Waiting for Finance/Admin verification").
 *   Approve / return: the expense, payroll-batch and purchase-request routes above.
 *   GET /api/projects/:id/transactions?from=&to=&line_item=&category=PS|MOOE&page=
 *                                           -> verified expenses, 25 per page (any project member)
 *   GET /api/documents/:id/download?inline=1 -> images / PDF shown inline (previews)
 *
 * Research Assistant pages (research-assistant of the project; 403 otherwise):
 *   GET /api/projects/:id/assistant-overview -> { project, kpis: { drafts, waiting, returned, missing_receipts }, submissions, missing_receipts }
 *   GET /api/projects/:id/assistant-badges   -> { my_submissions } (= Returned to you, same query as the KPI)
 *   GET /api/projects/:id/my-submissions?tab=drafts|waiting|returned|approved|completed|all&type=
 *                                           -> { counts, items } (the user's own expenses, purchase requests, payroll batches)
 *   GET /api/projects/:id/my-submissions/:type/:id -> { item } (any stage; fields, next_step, returned_by, can_*)
 *   GET /api/projects/:id/documents?document_type=&record=expense|payroll|purchase_request (any project member)
 *   Payroll batch submit needs a payroll_file document (422 otherwise).
 *
 * Finance/Admin pages (finance-admin of the project; 403 otherwise):
 *   GET  /api/projects/:id/finance-overview  -> { project, kpis: { to_verify, verified_this_month, missing_receipts, total_spent, ... },
 *                                               queue, missing_receipts, recently_verified, late_receipts }
 *   GET  /api/projects/:id/finance-badges    -> { verification } (= To verify, same query as the KPI)
 *   GET  /api/projects/:id/verification-queue?tab=waiting|verified|rejected&type=expense|payroll
 *                                            -> { counts, items, late_receipts }
 *   GET  /api/projects/:id/verification-queue/:type/:id -> { item } (can_verify, blocked_text, fields)
 *   GET  /api/projects/:id/payroll-batches   -> { batches, rates_label } (every month, with next step)
 *   POST /api/expenses/:id/request-receipt   -> Notify RA (verified expense without a receipt; one audit entry)
 * Read-only for any project member:
 *   GET  /api/projects/:id/budget-summary    -> { project, kpis, monthly_expenses, category_summary, line_items, transactions }
 *   A verified expense with no receipt accepts one late receipt (POST /api/expenses/:id/documents, receipt only).
 *
 * All list endpoints accept `page` / `per_page` and return Laravel's default
 * paginator shape ({ data, meta, links }).
 */

export const ENDPOINTS = {
  csrf: '/sanctum/csrf-cookie',
  login: '/api/login',
  register: '/api/register',
  // The one list of MSU-Main colleges / units and departments (public): { units: [{ name, departments, other }], other: { name, office_label, office_max } }.
  academicUnits: '/api/academic-units',
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
  // Project set-up (Program Leader only; one audit entry per change):
  //   GET  project-options -> { funders, leaders }
  //   POST projects, PATCH projects/:id (funder, reference, period, total budget, project_leader_id)
  //   POST projects/:id/line-items, PATCH|DELETE line-items/:id
  //   POST projects/:id/tranches, PATCH|DELETE tranches/:id (unreleased only)
  programProjectOptions: '/api/program/project-options',
  programLineItems: (projectId) => `/api/program/projects/${projectId}/line-items`,
  programLineItem: (id) => `/api/program/line-items/${id}`,
  programTranches: (projectId) => `/api/program/projects/${projectId}/tranches`,
  programTranche: (id) => `/api/program/tranches/${id}`,
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
  // One request for the review side panel: status history, type-specific figures
  // (tranche / line items before and after / liquidated expenses / payroll batch)
  // and attachments. Program Leader and project members only (403 otherwise).
  approvalDetail: (id) => `/api/approvals/${id}`,
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
  payrollBatchStep: (id, action) => `/api/payroll-batches/${id}/${action}`,
  payrollBatchDocuments: (id) => `/api/payroll-batches/${id}/documents`,
  payRates: (projectId) => `/api/projects/${projectId}/pay-rates`,
  payRate: (projectId, userId) => `/api/projects/${projectId}/pay-rates/${userId}`,
  purchaseRequests: (projectId) => `/api/projects/${projectId}/purchase-requests`,
  purchaseRequest: (id) => `/api/purchase-requests/${id}`,
  purchaseRequestStep: (id, action) => `/api/purchase-requests/${id}/${action}`,
  purchaseRequestDocuments: (id) => `/api/purchase-requests/${id}/documents`,
  budgetRequests: (projectId) => `/api/projects/${projectId}/budget-requests`,
  resubmitBudgetRequest: (id) => `/api/budget-requests/${id}/resubmit`,
  withdrawBudgetRequest: (id) => `/api/budget-requests/${id}/withdraw`,
  approvalDocuments: (id) => `/api/approvals/${id}/documents`,
  balancing: (projectId) => `/api/projects/${projectId}/balancing`,
  // Project Leader pages (read-only; decisions use the workflow routes).
  leaderOverview: (projectId) => `/api/projects/${projectId}/leader-overview`,
  leaderBadges: (projectId) => `/api/projects/${projectId}/leader-badges`,
  budgetRequestsSeen: (projectId) => `/api/projects/${projectId}/budget-requests/seen`,
  reviewQueue: (projectId) => `/api/projects/${projectId}/review-queue`,
  reviewItem: (projectId, type, id) => `/api/projects/${projectId}/review-queue/${type}/${id}`,
  transactions: (projectId) => `/api/projects/${projectId}/transactions`,
  // Research Assistant pages (read-only; changes use the workflow routes).
  assistantOverview: (projectId) => `/api/projects/${projectId}/assistant-overview`,
  assistantBadges: (projectId) => `/api/projects/${projectId}/assistant-badges`,
  mySubmissions: (projectId) => `/api/projects/${projectId}/my-submissions`,
  mySubmission: (projectId, type, id) => `/api/projects/${projectId}/my-submissions/${type}/${id}`,
  projectDocuments: (projectId) => `/api/projects/${projectId}/documents`,
  // Finance/Admin pages (read-only; verify / reject use the workflow routes).
  financeOverview: (projectId) => `/api/projects/${projectId}/finance-overview`,
  financeBadges: (projectId) => `/api/projects/${projectId}/finance-badges`,
  verificationQueue: (projectId) => `/api/projects/${projectId}/verification-queue`,
  verificationItem: (projectId, type, id) => `/api/projects/${projectId}/verification-queue/${type}/${id}`,
  payrollBatches: (projectId) => `/api/projects/${projectId}/payroll-batches`,
  requestReceipt: (expenseId) => `/api/expenses/${expenseId}/request-receipt`,
  // Read-only budget summary for any project member (Researcher, Finance/Admin).
  budgetSummary: (projectId) => `/api/projects/${projectId}/budget-summary`,
  report: (projectId, type) => `/api/projects/${projectId}/reports/${type}`,
  payrollRegister: (projectId) => `/api/projects/${projectId}/payroll-register`,
  audit: '/api/audit',
  team: (projectId) => `/api/projects/${projectId}/team`,
  roles: '/api/roles',
  pendingUsers: '/api/users/pending',
  approveUser: (id) => `/api/users/${id}/approve`,
};
