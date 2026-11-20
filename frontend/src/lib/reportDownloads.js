import { ROLE_MAP } from './roles.js';
import { ROLE_NAV } from '../layouts/roleNav.js';

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
 * The Reports page eyebrow: the sidebar group the page sits in, then the
 * role ("Output · Project Leader"), like the role's other pages.
 */
export function reportsEyebrow(roleCode) {
  const group = (ROLE_NAV[roleCode] ?? []).find((item) => item.items.some((link) => link.to === '/reports'));
  const role = roleCode === 'FIN' ? 'Finance/Admin' : ROLE_MAP[roleCode]?.label;
  return [group?.label, role].filter(Boolean).join(' · ') || 'Reports';
}
