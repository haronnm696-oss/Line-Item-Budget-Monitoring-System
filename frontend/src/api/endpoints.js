/**
 * API CONTRACT — what this frontend calls. Routes marked (not built yet) do
 * not exist in `backend/routes/api.php`: calls to them fail with a clear
 * error ("Could not load…" / "Could not save…"); there is no sample-data
 * fallback (see api/http.js).
 *
 * Auth (Sanctum SPA cookie flow):
 *   GET  /sanctum/csrf-cookie              -> 204, sets XSRF-TOKEN cookie
 *   POST /api/login   { email, password }
 *        -> 200 { user: { id, name, email, role, role_slug, roleLabel, avatar_initials, avatar_url } }
 *           avatar_url: null, or a relative URL of the 256x256 profile photo
 *        -> 422 { message, errors } on bad credentials
 *        -> 403 { message } when the account is deactivated
 *        Email is globally unique. The controller resolves the frontend
 *        role code (PRG/PL/RES/RA/FIN) from users.role_id -> roles.slug,
 *        which is the user's primary role and decides the dashboard.
 *        Per-project permissions still come from project_user.role_id.
 *   POST /api/logout                        -> 204
 *   GET  /api/me                            -> 200 { user } | 401
 *
 * Profile photo (signed-in user; JPG/PNG/WebP, at most 2 MB; the server
 * crops it to a 256x256 JPEG):
 *   POST   /api/profile/avatar  multipart { avatar } -> 200 { user } | 422 errors.avatar
 *   DELETE /api/profile/avatar                       -> 200 { user }
 *   GET    /api/users/:id/avatar                     -> the JPEG (any signed-in user) | 404
 *
 * Dashboards (role-scoped, server decides what the caller may see):
 *   GET /api/program/overview               -> Program Leader overview (descriptive figures only)
 *   GET /api/projects/:id/leader-overview   -> Project Leader overview (see below)
 *
 * Projects:
 *   GET  /api/projects                      -> list (Program Leader: all; others: assigned projects only)
 *   GET  /api/projects/:id                  -> detail incl. lib_categories rollups and expenses (403 if not assigned)
 *   Projects are created and edited by the Program Leader under /api/program/projects.
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
 * Spent = verified expenses and verified payroll (ProjectFinancialService); pending
 * includes payroll submitted or approved by the Project Leader. Balancing rows carry
 * spent_payroll, pending_payroll and honoraria (personnel linked: no expenses allowed).
 *
 * Payroll (same workflow as expenses; a verified batch is spent, charged to each
 * row's PS line item, funder share; batch.over_balance lists line items it would
 * overspend, and submit / verify are refused while any). A batch covers a pay
 * period of consecutive months (one month or a quarter); periods of a project never
 * overlap. Row = monthly honorarium x months effective in the period; no deductions,
 * no allowances, net = gross.
 *   GET    /api/projects/:id/payroll?month=YYYY-MM[&to=YYYY-MM]
 *          -> { month, to, months, month_label, batch, rows: [{ basic, months, computation, gross, net,
 *               recorded_deductions }], totals: { gross, net, recorded_deductions }, legacy_amounts,
 *               overlap_text, rated_members, not_included: [{ name, reason, reason_text }], ps_line_items }
 *          (the batch covering `month`, else the period month..to as it would be prepared)
 *   POST   /api/projects/:id/payroll          -> { month, to?, lib_category_id (PS) } create + compute the period (RA / FIN);
 *          422 errors.period when it overlaps another batch
 *   POST   /api/projects/:id/payroll/compute  -> { month } recompute the draft / returned batch covering it (RA / FIN)
 *   PATCH  /api/payroll/:id                   -> { basic_rate?, lib_category_id? } while the batch is draft / returned
 *   POST   /api/payroll-batches/:id/submit | endorse | return { reason } | verify | reject { reason }
 *          (the whole period at once; submit refused while batch.needs_recompute)
 *   Documents: GET/POST /api/payroll-batches/:id/documents (document_type payroll_file | other)
 *   Who is paid and how much comes from Personnel (below).
 *
 * Personnel (PersonnelService): the team appointed by Special Order, paid PS honoraria
 * through payroll (Job Order / professional services go through expenses).
 *   GET    /api/projects/:id/personnel
 *          -> { access: edit|view|limited, period: { start, end }, personnel: [...],
 *               members?, ps_line_items? (edit only) }
 *          edit/view rows: { id, user_id, name, position, lib_category_id, line_item, special_order_no,
 *            effective_from, effective_to, monthly_honorarium, bank_account_masked ("****9170"),
 *            special_order_document: { id, file_name } | null }
 *          limited rows (Research Assistant): { id, user_id, name, position, effective_from, effective_to }
 *          Program Leader edits; Project Leader and Finance/Admin view; Researchers 403.
 *          The full bank account number is never returned here.
 *   POST   /api/program/projects/:id/personnel -> { user_id, position, lib_category_id (PS), special_order_no,
 *            effective_from, effective_to (inside the project period), monthly_honorarium, bank_account? }
 *   PATCH  /api/program/personnel/:id          -> same without user_id; bank_account absent = keep, null = remove
 *   DELETE /api/program/personnel/:id          (refused while a Special Order file is attached)
 *   POST   /api/program/personnel/:id/special-order -> multipart { file }   DELETE removes it
 *          Download: /api/documents/:id/download (Program Leader, Project Leader, Finance/Admin)
 *
 * Purchase requests (draft -> submitted -> endorsed, or returned; no Finance step;
 * an approved request ends as fulfilled or cancelled):
 *   GET    /api/projects/:id/purchase-requests?workflow_status=
 *   POST   /api/projects/:id/purchase-requests -> { purpose, supplier?, funding_source?,
 *            items: [{ lib_category_id, quantity, unit (pc|set|box|lot|ream|unit), description, unit_cost }] }
 *            (estimated cost = quantity x unit cost and the total are computed by the server; honoraria lines refused;
 *            the older { lib_category_id, description, quantity, estimated_amount } is still accepted as one "lot" item)
 *          -> purchase_request { purpose, total, items: [{ line_item, quantity, unit, unit_cost, estimated_cost, expense_linked }],
 *             pr_no, pr_date, sai_no, sai_date, alobs_no, alobs_date, ... }
 *   PATCH  /api/purchase-requests/:id          (draft / returned)
 *   DELETE /api/purchase-requests/:id          (own draft)
 *   POST   /api/purchase-requests/:id/submit (needs a quotation or invoice) | endorse | return { reason }
 *   POST   /api/purchase-requests/:id/cancel  -> { reason } approved with open items (also partly linked); Project Leader
 *            or requester; releases only open items ("Cancelled (partly fulfilled)")
 *   PATCH  /api/purchase-requests/:id/numbers -> { pr_no, pr_date, sai_no, sai_date, alobs_no, alobs_date } Finance/Admin, approved or fulfilled
 *   Submit and approve check each line item: its items may not exceed its available amount (counting pending).
 *   Fulfil: POST/PATCH an expense with purchase_request_id + purchase_request_item_ids (open items, all on the
 *           expense's line item; without ids: every open item on that line item). amount <= the items' total
 *           (lower releases the difference). Items: status open | linked | paid (+ expense_reference); request
 *           fulfilment: none | partial | full ("Partly fulfilled", "Fulfilled"); fulfilled once no item is open.
 *           Deleting a linked draft frees its items.
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
 *   GET /api/projects/:id/form-b            -> R&D Form B document in the official layout (PDF print view):
 *       { funder, header, details, columns, rows: [{ kind, label, level, implementing_agency, funder }],
 *         footer, signatories: [{ label, name, position }], paper: A4|Legal }
 *       Same rows as the /reports/form-b CSV. Funder names come from the project (never hard-coded).
 *       (percent cells are plain numbers, never "<0.1")
 *   GET /api/purchase-requests/:id/form      -> one purchase request in MSU's Purchase Request form (PDF print view):
 *       { id, project_code, reference, title ("PR - <code> - <PR No. or id>"), draft (not approved yet),
 *         header { title, university, city }, office_unit, department,
 *         numbers: [{ label: PR No.|SAI No.|ALOBS No., value, date }] (null = blank line),
 *         columns, rows: [{ item_no, quantity, unit, description, unit_cost, estimated_cost }], total,
 *         purpose, chargeable, project_title,
 *         signatories: [{ label: Requested by:|Noted by:|Approved by:, name, position }] }
 *       Requested by = the project's Project Leader; Noted/Approved by from Edit project details.
 *       RA, PL, FIN of the project and the Program Leader; Researchers 403.
 *   GET /api/purchase-requests/:id/form/csv  -> the same rows as CSV (plain numbers)
 *   GET /api/projects/:id/payroll-register?period=all|year|90d&month=YYYY-MM
 *       -> { project, period, period_label, has_recorded_deductions,
 *            months: [{ month, month_label, months, batch_id, verified_at, verified_by, rows, totals }], totals }
 *       VERIFIED payroll batches only; CSV: /reports/payroll-register with the same params.
 *       The Program Leader, and the project's Project Leader, Finance/Admin and Research Assistants;
 *       403 for Researchers (pay per person; also GET /payroll, payroll-batch files) and outsiders.
 *   GET /api/projects/:id also carries, for the printed reports:
 *       ledger_pending: [{ kind, date, type, amount }] (expenses and payroll waiting for verification;
 *       the printed Expense Ledger lists verified rows only and notes these),
 *       signatories: { project_leader, finance_admin } (names or null).
 *   GET /api/report-signatories -> { program_leader } ("Approved by" on the Executive report and Audit log print)
 *
 * Audit log (q: server-side search of user, record, action and project, all entries in scope):
 *   GET /api/audit?q=&project_id=&action=&type=&date=
 *   GET /api/program/audit?q=&action=&project=&user=&from=&to=&page=   (Program Leader)
 *
 * Project Leader pages (project-leader of the project; 403 otherwise):
 *   GET /api/projects/:id/leader-overview   -> { project, kpis, review, alerts, monthly_expenses, category_summary, transactions, requests }
 *                                           project.implementing_agency (Form B field, else IMPLEMENTING_AGENCY);
 *                                           alerts.tranche_count / alerts.line_item_count (0 = not set up yet)
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
 *   GET /api/projects/:id/assistant-overview -> { project, kpis: { drafts, waiting, returned, missing_receipts }, submissions }
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
 *   GET  /api/projects/:id/payroll-batches   -> { batches } (every pay period, with next step)
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
  profileAvatar: '/api/profile/avatar',
  // Password reset by emailed 6-digit code:
  //   POST forgot-password        { email }                 -> 202 { message, code_expires_in, resend_in } (same for any address)
  //   POST forgot-password/verify { email, code }           -> { reset_token, expires_in } | 422 errors.code "Invalid or expired code"
  //   POST reset-password         { email, reset_token, password, password_confirmation } -> { message } | 422 errors.reset_token / errors.password
  forgotPassword: '/api/forgot-password',
  verifyResetCode: '/api/forgot-password/verify',
  resetPassword: '/api/reset-password',
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
  // { funders, leaders: [{ id, name, leads: [{ id, code, title }] }] }: `leads` = the active
  // projects each leads (one active project per Project Leader; lib/leaderRule.js).
  // GET /api/program/projects and /api/program/users also return `leader_conflicts`.
  programProjectOptions: '/api/program/project-options',
  // Funders: GET list, POST { name, acronym }, PATCH :id. The short name
  // (acronym) labels the funder column of every Line-Item Budget.
  programFunders: '/api/program/funders',
  programFunder: (id) => `/api/program/funders/${id}`,
  programLineItems: (projectId) => `/api/program/projects/${projectId}/line-items`,
  programLineItem: (id) => `/api/program/line-items/${id}`,
  programTranches: (projectId) => `/api/program/projects/${projectId}/tranches`,
  // The first two releases (70% and 30% of the funder total); only while the project has no releases.
  programTranchesSplit: (projectId) => `/api/program/projects/${projectId}/tranches/split`,
  // One click: renumber scheduled, never-requested releases 1, 2, … by planned date.
  programTranchesRenumber: (projectId) => `/api/program/projects/${projectId}/tranches/renumber`,
  programTranche: (id) => `/api/program/tranches/${id}`,
  programApprovals: '/api/program/approvals',
  programAudit: '/api/program/audit',
  programDecision: (id) => `/api/program/approvals/${id}/decision`,
  programRegistrations: '/api/program/registrations',
  programRegistrationCount: '/api/program/registrations/count',
  programRegistrationDecision: (id) => `/api/program/registrations/${id}/decision`,
  programUsers: '/api/program/users',
  programUser: (id) => `/api/program/users/${id}`,
  programUserStatus: (id) => `/api/program/users/${id}/status`,
  programUserRole: (id) => `/api/program/users/${id}/role`,
  // One Program Leader: { user_id, password } hands the role over; the caller is deactivated and signed out.
  programTransferLeader: '/api/program/transfer-program-leader',
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
  personnel: (projectId) => `/api/projects/${projectId}/personnel`,
  programPersonnel: (projectId) => `/api/program/projects/${projectId}/personnel`,
  personnelRecord: (id) => `/api/program/personnel/${id}`,
  personnelSpecialOrder: (id) => `/api/program/personnel/${id}/special-order`,
  purchaseRequests: (projectId) => `/api/projects/${projectId}/purchase-requests`,
  purchaseRequest: (id) => `/api/purchase-requests/${id}`,
  purchaseRequestStep: (id, action) => `/api/purchase-requests/${id}/${action}`,
  purchaseRequestDocuments: (id) => `/api/purchase-requests/${id}/documents`,
  purchaseRequestForm: (id) => `/api/purchase-requests/${id}/form`,
  purchaseRequestFormCsv: (id) => `/api/purchase-requests/${id}/form/csv`,
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
  // Project documents (MOA, Forms A/B/C, other): any member lists them; the
  // Program Leader uploads and removes (program routes).
  projectSetupDocuments: (projectId) => `/api/projects/${projectId}/project-documents`,
  programProjectDocuments: (projectId) => `/api/program/projects/${projectId}/documents`,
  programProjectDocument: (id) => `/api/program/project-documents/${id}`,
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
  formB: (projectId) => `/api/projects/${projectId}/form-b`,
  payrollRegister: (projectId) => `/api/projects/${projectId}/payroll-register`,
  audit: '/api/audit',
  reportSignatories: '/api/report-signatories',
  pendingUsers: '/api/users/pending',
  approveUser: (id) => `/api/users/${id}/approve`,
};
