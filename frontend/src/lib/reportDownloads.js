/**
 * The formats in a report's "Download" menu (components/DownloadMenu.jsx),
 * the same for every role: PDF then CSV; Form B names its official layout.
 */
export function reportDownloadOptions(reportKey) {
  return [
    { key: 'pdf', label: reportKey === 'form-b' ? 'Form B (PDF)' : 'PDF' },
    { key: 'csv', label: 'CSV' },
  ];
}

/**
 * The Payroll register shows pay per person: not for Researchers (the API
 * answers 403 for them too).
 */
export function canSeePayrollRegister(roleCode) {
  return roleCode !== 'RES';
}

/** The report cards a role sees, in page order. */
export function reportTypesFor(roleCode, types) {
  return canSeePayrollRegister(roleCode) ? types : types.filter((type) => type.key !== 'payroll-register');
}

/**
 * Whether the Reports page shows its Project parameter. The Project Leader
 * never sees it (the page follows their project). Researchers and
 * Finance/Admin see it only once their projects have loaded and there is
 * more than one to choose from; the Program Leader always.
 */
export function showReportProjectField(roleCode, projectsStatus, projectCount) {
  if (roleCode === 'PL') return false;
  if (roleCode === 'RES' || roleCode === 'FIN') return projectsStatus === 'ready' && projectCount > 1;
  return true;
}
