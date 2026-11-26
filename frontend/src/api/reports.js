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

/** { program_leader }: the "Approved by" name on program-wide printed reports (null when unknown). */
export async function fetchProgramLeaderName() {
  try {
    const data = await apiGet(ENDPOINTS.reportSignatories);
    return data?.program_leader ?? null;
  } catch {
    // The report still prints, with a blank signature line.
    return null;
  }
}

/** R&D Form B in the official layout (the PDF print view). */
export function fetchFormB(projectId) {
  return apiGet(ENDPOINTS.formB(projectId));
}

/** A report CSV built by the backend: { blob, filename }. */
export function downloadReportCsv(projectId, type, params = {}) {
  return downloadCsv(ENDPOINTS.report(projectId, type), `${type}.csv`, params);
}

/** One purchase request in MSU's Purchase Request form (the PDF print view). */
export function fetchPurchaseRequestForm(id) {
  return apiGet(ENDPOINTS.purchaseRequestForm(id));
}

/** The same purchase request form as CSV: { blob, filename }. */
export function downloadPurchaseRequestCsv(id) {
  return downloadCsv(ENDPOINTS.purchaseRequestFormCsv(id), `purchase-request-${id}.csv`);
}

async function downloadCsv(url, fallbackName, params = {}) {
  const response = await client.get(url, {
    params: Object.fromEntries(Object.entries(params).filter(([, value]) => value)),
    responseType: 'blob',
  });
  const disposition = response.headers?.['content-disposition'] ?? '';
  const match = /filename="?([^";]+)"?/i.exec(disposition);
  return { blob: response.data, filename: match?.[1] ?? fallbackName };
}
