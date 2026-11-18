import client from './client';
import { apiGet } from './http';
import { ENDPOINTS } from './endpoints';

/** Payroll register (verified batches only). params: { period } or { month: 'YYYY-MM' }. */
export function fetchPayrollRegister(projectId, params = {}) {
  const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value)).toString();
  return apiGet(`${ENDPOINTS.payrollRegister(projectId)}${query ? `?${query}` : ''}`);
}

/** The register CSV, built by the backend (PAYROLL_RATES_LABEL on its first line when set). */
export async function downloadPayrollRegisterCsv(projectId, params = {}) {
  const response = await client.get(ENDPOINTS.report(projectId, 'payroll-register'), {
    params: Object.fromEntries(Object.entries(params).filter(([, value]) => value)),
    responseType: 'blob',
  });
  const disposition = response.headers?.['content-disposition'] ?? '';
  const match = /filename="?([^";]+)"?/i.exec(disposition);
  return { blob: response.data, filename: match?.[1] ?? 'payroll-register.csv' };
}
