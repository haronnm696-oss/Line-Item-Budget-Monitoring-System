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
  { id: 1, code: 'MOST-RD-2026-001', title: 'Enhancing Urban Mobility', agency: 'MOST-BARMM', implementing_agency: 'MSU-Marawi', project_leader: 'Prof. Mudzna M. Asakil', program: 'Urban Mobility Program', budget: 3178376, spent: 1730000, counterpart: 1178376, funding: 2000000, period: 'Jan – Dec 2026', status: 'Ongoing' },
  { id: 2, code: 'RP-2026-009', title: 'Halal Goat Value Chain Development, Lanao del Sur', agency: 'DOST-PCAARRD', project_leader: 'Dr. S. Mangondaya', program: 'Halal Goat Value Chain Program', budget: 1870000, spent: 1514700, period: 'Mar 2026 – Feb 2027', status: 'Ongoing' },
  { id: 3, code: 'RP-2025-031', title: 'Meranaw Weaving Heritage Digital Archive', agency: 'CHED', project_leader: 'Dr. N. Guro', program: 'Cultural Heritage Program', budget: 940000, spent: 927780, period: 'Jun 2025 – May 2026', status: 'Completed' },
  { id: 4, code: 'RP-2026-021', title: 'Community Nutrition Surveillance, Marawi Resettlement Sites', agency: 'DOST-PCHRD', project_leader: 'Dr. F. Alonto', program: 'Community Nutrition Program', budget: 1210000, spent: 148830, period: 'Aug 2026 – Jul 2027', status: 'Planning' },
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
  { id: 388, date: '2026-09-14', ref: 'OR-2841', desc: 'Water sampling reagents — 3rd batch', category: 'Supplies & Materials (IoT Sensors, Laptops)', amount: 18450, status: 'Pending', recorded_by: 'Norjannah Haron', verified_by: null },
  { id: 382, date: '2026-09-09', ref: 'TV-0917', desc: 'Field travel — Marawi to Binidayan station', category: 'Traveling Expenses', amount: 7860, status: 'Verified', recorded_by: 'Norjannah Haron', verified_by: 'Faisal Sarip' },
  { id: 381, date: '2026-09-05', ref: 'BI-1130', desc: 'Laboratory analysis fee — MSU-NSRC', category: 'Professional Services (Programmers/Enumerators)', amount: 24000, status: 'Verified', recorded_by: 'Norjannah Haron', verified_by: 'Faisal Sarip' },
  { id: 379, date: '2026-09-02', ref: 'OR-2790', desc: 'Reproduction of survey forms', category: 'Supplies & Materials (IoT Sensors, Laptops)', amount: 1120, status: 'Rejected', recorded_by: 'Norjannah Haron', verified_by: null },
  { id: 376, date: '2026-08-28', ref: 'OR-2765', desc: 'GPS unit battery replacements', category: 'Supplies & Materials (IoT Sensors, Laptops)', amount: 3540, status: 'Verified', recorded_by: 'Norjannah Haron', verified_by: 'Faisal Sarip' },
  { id: 371, date: '2026-08-21', ref: 'TV-0902', desc: 'Boat rental — sampling stations 4–6', category: 'Traveling Expenses', amount: 9500, status: 'Verified', recorded_by: 'Norjannah Haron', verified_by: 'Faisal Sarip' },
  { id: 370, date: '2026-08-15', ref: 'OR-2750', desc: 'Internet subscription — September', category: 'Communication Expenses', amount: 10500, status: 'Verified', recorded_by: 'Faisal Sarip', verified_by: 'Faisal Sarip' },
  { id: 369, date: '2026-08-10', ref: 'TV-0895', desc: 'Field travel — Marawi to Balo-i station', category: 'Traveling Expenses', amount: 6200, status: 'Verified', recorded_by: 'Norjannah Haron', verified_by: 'Faisal Sarip' },
  { id: 368, date: '2026-08-05', ref: 'PR-2026-08', desc: 'Enumerators honoraria — Q2 field work', category: 'Professional Services (Programmers/Enumerators)', amount: 45000, status: 'Verified', recorded_by: 'Faisal Sarip', verified_by: 'Faisal Sarip' },
  { id: 367, date: '2026-07-30', ref: 'SV-2026-07', desc: 'Cloud server rent — AWS July', category: 'Cloud/Server Rent', amount: 12000, status: 'Verified', recorded_by: 'Faisal Sarip', verified_by: 'Faisal Sarip' },
  { id: 366, date: '2026-07-25', ref: 'OR-2710', desc: 'Office supplies — printing and binding', category: 'Supplies & Materials (IoT Sensors, Laptops)', amount: 8300, status: 'Verified', recorded_by: 'Norjannah Haron', verified_by: 'Faisal Sarip' },
  { id: 365, date: '2026-07-15', ref: 'UT-2026-07', desc: 'Electricity — project office July', category: 'Utilities', amount: 9800, status: 'Verified', recorded_by: 'Faisal Sarip', verified_by: 'Faisal Sarip' },
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
  { id: 1, ts: '2026-09-15 16:42:00', date: '2026-09-15', user: 'Norjannah Haron', action: 'created', record: 'Payroll #214', recordType: 'Payroll', change: 'Sep 2026 · S. Dimaporo · net ₱16,674.00', ip: '203.87.141.22' },
  { id: 2, ts: '2026-09-14 10:05:00', date: '2026-09-14', user: 'Norjannah Haron', action: 'created', record: 'Expense #388', recordType: 'Expense', change: 'OR-2841 · ₱18,450.00 · Supplies & Materials', ip: '203.87.141.22' },
  { id: 3, ts: '2026-09-09 14:31:00', date: '2026-09-09', user: 'Faisal Sarip', action: 'verified', record: 'Expense #382', recordType: 'Expense', change: 'status: pending → verified', ip: '203.87.140.8' },
  { id: 4, ts: '2026-09-02 09:12:00', date: '2026-09-02', user: 'Faisal Sarip', action: 'rejected', record: 'Expense #379', recordType: 'Expense', change: 'status: pending → rejected · "duplicate of OR-2781"', ip: '203.87.140.8' },
  { id: 5, ts: '2026-09-01 08:57:00', date: '2026-09-01', user: 'Dr. Aminah Macarambon', action: 'approved', record: 'Payroll · Aug 2026', recordType: 'Payroll', change: '4 records · total net ₱64,199.25', ip: '203.87.139.4' },
  { id: 6, ts: '2026-08-30 11:20:00', date: '2026-08-30', user: 'Dr. Rahana Bayabao', action: 'updated', record: 'LibCategory #6', recordType: 'LibCategory', change: 'allocated_amount: ₱800,000 → ₱860,000', ip: '203.87.141.19' },
  { id: 7, ts: '2026-08-28 15:30:00', date: '2026-08-28', user: 'Norjannah Haron', action: 'created', record: 'Expense #376', recordType: 'Expense', change: 'OR-2765 · GPS unit battery replacements · ₱3,540.00', ip: '203.87.141.22' },
  { id: 8, ts: '2026-08-25 09:00:00', date: '2026-08-25', user: 'Faisal Sarip', action: 'verified', record: 'Expense #376', recordType: 'Expense', change: 'status: pending → verified', ip: '203.87.140.8' },
  { id: 9, ts: '2026-08-21 10:15:00', date: '2026-08-21', user: 'Norjannah Haron', action: 'created', record: 'Expense #371', recordType: 'Expense', change: 'TV-0902 · Boat rental — sampling stations 4–6 · ₱9,500.00', ip: '203.87.141.22' },
  { id: 10, ts: '2026-08-15 16:20:00', date: '2026-08-15', user: 'Faisal Sarip', action: 'verified', record: 'Expense #370', recordType: 'Expense', change: 'OR-2750 · Internet subscription — September · ₱10,500.00', ip: '203.87.140.8' },
  { id: 11, ts: '2026-08-10 11:05:00', date: '2026-08-10', user: 'Norjannah Haron', action: 'created', record: 'Expense #369', recordType: 'Expense', change: 'TV-0895 · Field travel — Marawi to Balo-i station · ₱6,200.00', ip: '203.87.141.22' },
  { id: 12, ts: '2026-08-05 14:00:00', date: '2026-08-05', user: 'Faisal Sarip', action: 'verified', record: 'Expense #368', recordType: 'Expense', change: 'PR-2026-08 · Enumerators honoraria — Q2 field work · ₱45,000.00', ip: '203.87.140.8' },
  { id: 13, ts: '2026-07-30 09:45:00', date: '2026-07-30', user: 'Faisal Sarip', action: 'verified', record: 'Expense #367', recordType: 'Expense', change: 'SV-2026-07 · Cloud server rent — AWS July · ₱12,000.00', ip: '203.87.140.8' },
  { id: 14, ts: '2026-07-25 10:30:00', date: '2026-07-25', user: 'Norjannah Haron', action: 'created', record: 'Expense #366', recordType: 'Expense', change: 'OR-2710 · Office supplies — printing and binding · ₱8,300.00', ip: '203.87.141.22' },
  { id: 15, ts: '2026-07-15 11:15:00', date: '2026-07-15', user: 'Faisal Sarip', action: 'verified', record: 'Expense #365', recordType: 'Expense', change: 'UT-2026-07 · Electricity — project office July · ₱9,800.00', ip: '203.87.140.8' },
  { id: 16, ts: '2026-07-10 08:30:00', date: '2026-07-10', user: 'Dr. Rahana Bayabao', action: 'updated', record: 'Project #1', recordType: 'Project', change: 'budget: ₱3,178,376.00 → ₱3,278,376.00', ip: '203.87.141.19' },
];

export const team = [
  { name: 'Dr. Aminah Macarambon', email: 'a.macarambon@msumain.edu.ph', empId: 'MSU-04182', position: 'Professor VI, CNSM', role: 'Project Leader', assigned: 'Jan 06, 2026', status: 'Active' },
  { name: 'Dr. Rahana Bayabao', email: 'r.bayabao@msumain.edu.ph', empId: 'MSU-05561', position: 'Associate Professor, CICS', role: 'Program Leader', assigned: 'Jan 06, 2026', status: 'Active' },
  { name: 'Norjannah Haron', email: 'n.haron@msumain.edu.ph', empId: 'MSU-09214', position: 'Research Assistant', role: 'Research Assistant', assigned: 'Jan 10, 2026', status: 'Active' },
  { name: 'Faisal Sarip', email: 'f.sarip@msumain.edu.ph', empId: 'MSU-03327', position: 'Admin Officer IV, OVCRE', role: 'Finance/Admin Staff', assigned: 'Jan 10, 2026', status: 'Active' },
  { name: 'Sittie Dimaporo', email: 's.dimaporo@msumain.edu.ph', empId: 'MSU-11048', position: 'Research Aide I', role: 'Researcher', assigned: 'Feb 01, 2026', status: 'Inactive' },
];

// Master system user registry — every registered account in the database.
export const systemUsers = [
  {
    id: 1,
    name: 'Dr. Aminah Macarambon',
    email: 'a.macarambon@msumain.edu.ph',
    empId: 'MSU-04182',
    position: 'Professor VI, CNSM',
    role: 'Project Leader',
    status: 'Active',
    joined: 'Jan 06, 2026',
  },
  {
    id: 2,
    name: 'Dr. Rahana Bayabao',
    email: 'r.bayabao@msumain.edu.ph',
    empId: 'MSU-05561',
    position: 'Associate Professor, CICS',
    role: 'Program Leader',
    status: 'Active',
    joined: 'Jan 06, 2026',
  },
  {
    id: 3,
    name: 'Norjannah Haron',
    email: 'n.haron@msumain.edu.ph',
    empId: 'MSU-09214',
    position: 'Research Assistant',
    role: 'Research Assistant',
    status: 'Active',
    joined: 'Jan 10, 2026',
  },
  {
    id: 4,
    name: 'Faisal Sarip',
    email: 'f.sarip@msumain.edu.ph',
    empId: 'MSU-03327',
    position: 'Admin Officer IV, OVCRE',
    role: 'Finance/Admin Staff',
    status: 'Active',
    joined: 'Jan 10, 2026',
  },
  {
    id: 5,
    name: 'Sittie Dimaporo',
    email: 's.dimaporo@msumain.edu.ph',
    empId: 'MSU-11048',
    position: 'Research Aide I',
    role: 'Researcher',
    status: 'Inactive',
    joined: 'Feb 01, 2026',
  },
];

// Project assignments — which user is on which project, with their role on that project.
export const projectAssignments = [
  { userId: 1, projectId: 1, role: 'Project Leader', assigned: 'Jan 06, 2026' },
  { userId: 2, projectId: 1, role: 'Program Leader', assigned: 'Jan 06, 2026' },
  { userId: 3, projectId: 1, role: 'Research Assistant', assigned: 'Jan 10, 2026' },
  { userId: 4, projectId: 1, role: 'Finance/Admin Staff', assigned: 'Jan 10, 2026' },
  { userId: 5, projectId: 1, role: 'Researcher', assigned: 'Feb 01, 2026' },
  { userId: 1, projectId: 2, role: 'Project Leader', assigned: 'Mar 01, 2026' },
  { userId: 3, projectId: 3, role: 'Researcher', assigned: 'Jun 01, 2025' },
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
