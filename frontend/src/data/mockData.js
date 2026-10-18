// Placeholder data matching the approved mockup. Every page reads through
// src/api/*.js, which tries the real endpoint first and falls back to this
// file only when VITE_USE_MOCK=true or the request fails — see api/http.js.

export const ROLE_MAP = {
  PRG: { dash: 'program', slug: 'program-leader', label: 'Program Leader', name: 'Dr. Rahana Bayabao', initials: 'RB' },
  PL: { dash: 'project-leader', slug: 'project-leader', label: 'Project Leader', name: 'Dr. Aminah Macarambon', initials: 'AM' },
  RES: { dash: 'researcher', slug: 'researcher', label: 'Researcher', name: 'Sittie Dimaporo', initials: 'SD' },
  RA: { dash: 'research-assistant', slug: 'research-assistant', label: 'Research Assistant', name: 'Norjannah Haron', initials: 'NH' },
  FIN: { dash: 'finance', slug: 'finance-admin', label: 'Finance/Admin Staff', name: 'Faisal Sarip', initials: 'FS' },
};

// Backend slug -> frontend code, mirrors AuthController::SLUG_TO_CODE.
export const SLUG_TO_CODE = {
  'program-leader': 'PRG',
  'project-leader': 'PL',
  researcher: 'RES',
  'research-assistant': 'RA',
  'finance-admin': 'FIN',
};

export function normalizeRole(user) {
  if (!user) return null;
  if (user.role && ROLE_MAP[user.role]) return user.role;
  if (user.role_slug && SLUG_TO_CODE[user.role_slug]) return SLUG_TO_CODE[user.role_slug];
  return null;
}

export const CURRENT_PROJECT = {
  id: 1,
  code: 'MOST-RD-2026-001',
  title: 'Enhancing Urban Mobility',
  implementing_agency: 'MSU-Marawi',
  funding_agency: 'MOST-BARMM',
  project_leader: 'Prof. Mudzna M. Asakil',
  period: 'Jan 2026 – Dec 2026',
  status: 'Ongoing',

  // Dual-funding structure per MOST R&D Form B
  counterpart_budget: 1178376.00,   // Implementing Agency Counterpart (MSU-Marawi)
  funding_budget: 2000000.00,       // Funding Agency (MOST-BARMM)
  total_budget: 3178376.00,         // Grand Total

  // Disbursement figures (mock)
  total_disbursed: 1730000.00,
};

export const monthlyExpensesChart = [
  { m: 'Jan', ps: 34, mooe: 22 }, { m: 'Feb', ps: 37, mooe: 37 },
  { m: 'Mar', ps: 34, mooe: 53 }, { m: 'Apr', ps: 34, mooe: 28 },
  { m: 'May', ps: 34, mooe: 59 }, { m: 'Jun', ps: 34, mooe: 17 },
  { m: 'Jul', ps: 34, mooe: 75 }, { m: 'Aug', ps: 34, mooe: 31 },
  { m: 'Sep', ps: 34, mooe: 10 },
];

export const projects = [
  { id: 1, code: 'MOST-RD-2026-001', title: 'Enhancing Urban Mobility', agency: 'MOST-BARMM', implementing_agency: 'MSU-Marawi', project_leader: 'Prof. Mudzna M. Asakil', budget: 3178376, spent: 1730000, counterpart: 1178376, funding: 2000000, period: 'Jan – Dec 2026', status: 'Ongoing' },
  { id: 2, code: 'RP-2026-009', title: 'Halal Goat Value Chain Development, Lanao del Sur', agency: 'DOST-PCAARRD', budget: 1870000, spent: 1514700, period: 'Mar 2026 – Feb 2027', status: 'Ongoing', leader: 'Dr. S. Mangondaya' },
  { id: 3, code: 'RP-2025-031', title: 'Meranaw Weaving Heritage Digital Archive', agency: 'CHED', budget: 940000, spent: 927780, period: 'Jun 2025 – May 2026', status: 'Completed', leader: 'Dr. N. Guro' },
  { id: 4, code: 'RP-2026-021', title: 'Community Nutrition Surveillance, Marawi Resettlement Sites', agency: 'DOST-PCHRD', budget: 1210000, spent: 148830, period: 'Aug 2026 – Jul 2027', status: 'Planning', leader: 'Dr. F. Alonto' },
];

export const libCategoriesPS = [
  { name: 'Project Leader Honoraria (Prof. Mudzna M. Asakil)', allocated: 400000, spent: 260000 },
  { name: 'Research Assistant Honoraria (2 personnel)', allocated: 360000, spent: 240000 },
  { name: 'Researcher Honoraria', allocated: 240000, spent: 160000 },
  { name: 'Program Leader Indirect Honoraria', allocated: 200000, spent: 120000 },
];
export const libCategoriesMOOE = [
  { name: 'Traveling Expenses', allocated: 350000, spent: 180000 },
  { name: 'Supplies & Materials (IoT Sensors, Laptops)', allocated: 600000, spent: 320000 },
  { name: 'Communication Expenses', allocated: 120000, spent: 65000 },
  { name: 'Professional Services (Programmers/Enumerators)', allocated: 500000, spent: 250000 },
  { name: 'Cloud/Server Rent', allocated: 200000, spent: 90000 },
  { name: 'Utilities', allocated: 208376, spent: 45000 },
];

export const expenses = [
  { id: 388, date: 'Sep 14', ref: 'OR-2841', desc: 'Water sampling reagents — 3rd batch', category: 'Supplies & Materials', amount: 18450, status: 'Pending' },
  { id: 382, date: 'Sep 09', ref: 'TV-0917', desc: 'Field travel — Marawi to Binidayan station', category: 'Travel Expenses', amount: 7860, status: 'Verified' },
  { id: 381, date: 'Sep 05', ref: 'BI-1130', desc: 'Laboratory analysis fee — MSU-NSRC', category: 'Other Services', amount: 24000, status: 'Verified' },
  { id: 379, date: 'Sep 02', ref: 'OR-2790', desc: 'Reproduction of survey forms', category: 'Supplies & Materials', amount: 1120, status: 'Rejected' },
  { id: 376, date: 'Aug 28', ref: 'OR-2765', desc: 'GPS unit battery replacements', category: 'Supplies & Materials', amount: 3540, status: 'Verified' },
  { id: 371, date: 'Aug 21', ref: 'TV-0902', desc: 'Boat rental — sampling stations 4–6', category: 'Travel Expenses', amount: 9500, status: 'Verified' },
];

export const payrollRun = {
  month: 'September 2026',
  rows: [
    { id: 211, name: 'Dr. A. Macarambon', position: 'Project Leader', category: 'Project Leader honorarium', basic: 20000, gross: 20000, deductions: 1000, net: 19000, status: 'Draft' },
    { id: 212, name: 'S. Dimaporo', position: 'Research Aide I', category: 'Salaries — Research Aides', basic: 19000, gross: 19000, deductions: 2326, net: 16674, status: 'Draft' },
    { id: 213, name: 'J. Pangandaman', position: 'Research Aide I', category: 'Salaries — Research Aides', basic: 19000, gross: 19000, deductions: 2326, net: 16674, status: 'Draft' },
    { id: 214, name: 'Engr. R. Alonto', position: 'Consultant', category: 'Honoraria — Consultants', basic: 12475, gross: 12475, deductions: 623.75, net: 11851.25, status: 'Not started' },
  ],
  deductionBreakdown: [
    { type: 'Withholding tax', amount: 950 },
    { type: 'PhilHealth', amount: 475 },
    { type: 'Pag-IBIG', amount: 200 },
    { type: 'SSS', amount: 701 },
  ],
};

export const auditLog = [
  { id: 1, ts: 'Sep 15 · 16:42', user: 'N. Haron', action: 'created', record: 'Payroll #214', change: 'Sep 2026 · S. Dimaporo · net ₱16,674.00', ip: '203.87.141.22' },
  { id: 2, ts: 'Sep 14 · 10:05', user: 'N. Haron', action: 'created', record: 'Expense #388', change: 'OR-2841 · ₱18,450.00 · Supplies & Materials', ip: '203.87.141.22' },
  { id: 3, ts: 'Sep 09 · 14:31', user: 'F. Sarip', action: 'verified', record: 'Expense #382', change: 'status: pending → verified', ip: '203.87.140.8' },
  { id: 4, ts: 'Sep 02 · 09:12', user: 'F. Sarip', action: 'rejected', record: 'Expense #379', change: 'status: pending → rejected · "duplicate of OR-2781"', ip: '203.87.140.8' },
  { id: 5, ts: 'Sep 01 · 08:57', user: 'Dr. A. Macarambon', action: 'approved', record: 'Payroll · Aug 2026', change: '4 records · total net ₱64,199.25', ip: '203.87.139.4' },
  { id: 6, ts: 'Aug 30 · 11:20', user: 'R. Bayabao', action: 'updated', record: 'LibCategory #6', change: 'allocated_amount: ₱800,000 → ₱860,000', ip: '203.87.141.19' },
];

export const team = [
  { name: 'Dr. Aminah Macarambon', email: 'a.macarambon@msumain.edu.ph', empId: 'MSU-04182', position: 'Professor VI, CNSM', role: 'Project Leader', assigned: 'Jan 06, 2026', status: 'Active' },
  { name: 'Dr. Rahana Bayabao', email: 'r.bayabao@msumain.edu.ph', empId: 'MSU-05561', position: 'Associate Professor, CICS', role: 'Program Leader', assigned: 'Jan 06, 2026', status: 'Active' },
  { name: 'Norjannah Haron', email: 'n.haron@msumain.edu.ph', empId: 'MSU-09214', position: 'Research Assistant', role: 'Research Assistant', assigned: 'Jan 10, 2026', status: 'Active' },
  { name: 'Faisal Sarip', email: 'f.sarip@msumain.edu.ph', empId: 'MSU-03327', position: 'Admin Officer IV, OVCRE', role: 'Finance/Admin Staff', assigned: 'Jan 10, 2026', status: 'Active' },
  { name: 'Sittie Dimaporo', email: 's.dimaporo@msumain.edu.ph', empId: 'MSU-11048', position: 'Research Aide I', role: 'Researcher', assigned: 'Feb 01, 2026', status: 'Inactive' },
];

export const researchSubmissions = [
  { title: 'Q2 Field Data Summary', type: 'Progress report', submitted: 'Jul 04', status: 'Reviewed' },
  { title: 'Water Sampling Protocol v2', type: 'Methodology note', submitted: 'Aug 12', status: 'Reviewed' },
  { title: 'Q3 Progress & Analysis Draft', type: 'Progress report', submitted: 'Sep 15', status: 'Pending review' },
];

export const milestones = [
  { name: 'Literature review', pct: 100 },
  { name: 'Survey instrument design', pct: 100 },
  { name: 'Field data collection', pct: 60 },
  { name: 'Water quality analysis', pct: 20 },
  { name: 'Manuscript drafting', pct: 2 },
];

export const raTasks = [
  { title: 'Log September water sampling reagent purchase', due: 'Due Sep 16 · Overdue', done: false },
  { title: 'Upload OR-2841 receipt scan', due: 'Due Sep 17', done: false },
  { title: 'Compute September payroll for research aides', due: 'Due Sep 18', done: false },
  { title: 'File August travel liquidation', due: 'Completed Sep 10', done: true },
];

export const raUploads = [
  { name: 'OR-2841 receipt.pdf', meta: 'Supplies & Materials · Sep 14' },
  { name: 'TV-0917 boat rental OR.jpg', meta: 'Travel Expenses · Sep 09' },
  { name: 'Lab analysis invoice.pdf', meta: 'Other Services · Sep 05' },
];

export const allProjectsForProgram = [
  { name: 'Enhancing Urban Mobility', leader: 'Prof. Mudzna M. Asakil', budget: 3178376, pct: 54.4, counterpart: 1178376, funding: 2000000 },
  { name: 'Halal Goat Value Chain Development', leader: 'Dr. S. Mangondaya', budget: 1870000, pct: 81.0 },
  { name: 'Community Nutrition Surveillance', leader: 'Dr. F. Alonto', budget: 1210000, pct: 12.3 },
  { name: 'Meranaw Weaving Heritage Archive', leader: 'Dr. N. Guro', budget: 940000, pct: 98.7 },
];

export const pendingApprovals = [
  { title: 'Payroll · September 2026', detail: '4 personnel · gross ₱70,475.00 · computed by N. Haron' },
  { title: 'Line item revision request', detail: 'Move ₱40,000 from Other Services to Travel Expenses' },
];
