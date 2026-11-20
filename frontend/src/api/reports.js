import client from './client';
import { apiGet } from './http';
import { ENDPOINTS } from './endpoints';

/** Payroll register (verified batches only). params: { period } or { month: 'YYYY-MM' }. */
export function fetchPayrollRegister(projectId, params = {}) {
  const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value)).toString();
  return apiGet(`${ENDPOINTS.payrollRegister(projectId)}${query ? `?${query}` : ''}`);
}

/** The register CSV, built by the backend. */
export function downloadPayrollRegisterCsv(projectId, params = {}) {
  return downloadReportCsv(projectId, 'payroll-register', params);
}

/** R&D Form B in the official layout (the PDF print view). */
export function fetchFormB(projectId) {
  return apiGet(ENDPOINTS.formB(projectId));
}

/** A report CSV built by the backend: { blob, filename }. */
export async function downloadReportCsv(projectId, type, params = {}) {
  const response = await client.get(ENDPOINTS.report(projectId, type), {
    params: Object.fromEntries(Object.entries(params).filter(([, value]) => value)),
    responseType: 'blob',
  });
  const disposition = response.headers?.['content-disposition'] ?? '';
  const match = /filename="?([^";]+)"?/i.exec(disposition);
  return { blob: response.data, filename: match?.[1] ?? `${type}.csv` };
}
