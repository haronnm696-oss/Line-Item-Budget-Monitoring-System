import { ENDPOINTS } from './endpoints';
import client from './client';
import { apiGet, apiWrite } from './http';
import { performWrite } from '../lib/apiResult';

/** The workflow route that approves / returns each queue record type. */
const STEP_URL = {
  expense: (id, action) => (action === 'approve' ? ENDPOINTS.endorseExpense(id) : ENDPOINTS.returnExpense(id)),
  payroll: (id, action) => ENDPOINTS.payrollBatchStep(id, action === 'approve' ? 'endorse' : 'return'),
  purchase_request: (id, action) => ENDPOINTS.purchaseRequestStep(id, action === 'approve' ? 'endorse' : 'return'),
};

/** Approve, or return with a reason, through the record's existing workflow route. */
export function decideItem(item, action, reason) {
  const url = STEP_URL[item.type]?.(item.id, action);
  if (!url) return Promise.reject(new Error('Unknown record type.'));
  return apiWrite('post', url, action === 'return' ? { reason } : undefined);
}

export function fetchReviewItem(projectId, type, id) {
  return apiGet(ENDPOINTS.reviewItem(projectId, type, id));
}

const BASE = String(import.meta.env.VITE_API_URL ?? '').replace(/\/+$/, '');

/** Absolute URL of an API path for links and previews (same base as the axios client). */
export function apiUrl(path) {
  return `${BASE}${path}`;
}

/** Documents of a queue record or a transaction (expense). */
export function fetchDocuments(type, id) {
  const url = {
    expense: ENDPOINTS.expenseDocuments,
    payroll: ENDPOINTS.payrollBatchDocuments,
    purchase_request: ENDPOINTS.purchaseRequestDocuments,
    approval: ENDPOINTS.approvalDocuments,
  }[type]?.(id);
  return url ? apiGet(url) : Promise.resolve({ documents: [] });
}

/**
 * POST /api/projects/:id/budget-requests. A liquidation goes as multipart
 * with files[]; the others as JSON. The server's 422 messages are kept on
 * the rejected error (error.response.data.errors).
 */
export function submitBudgetRequest(projectId, fields, files = []) {
  return postWithFiles(ENDPOINTS.budgetRequests(projectId), fields, files);
}

/**
 * POST /api/budget-requests/:id/resubmit: a returned request, edited. The
 * type stays; removeDocumentIds are documents the maker deletes.
 */
export function resubmitBudgetRequest(id, fields, files = [], removeDocumentIds = []) {
  return postWithFiles(ENDPOINTS.resubmitBudgetRequest(id), { ...fields, remove_document_ids: removeDocumentIds }, files);
}

/** POST /api/budget-requests/:id/withdraw: the maker closes a returned request. */
export function withdrawBudgetRequest(id) {
  return apiWrite('post', ENDPOINTS.withdrawBudgetRequest(id));
}

/** JSON without files; multipart (arrays as key[]) with files[]. */
function postWithFiles(url, fields, files) {
  if (files.length === 0) return performWrite(() => client.post(url, fields));

  const form = new FormData();
  Object.entries(fields).forEach(([key, value]) => {
    if (Array.isArray(value)) value.forEach((item) => form.append(`${key}[]`, item));
    else if (value !== '' && value != null) form.append(key, value);
  });
  files.forEach((file) => form.append('files[]', file));
  return performWrite(() => client.post(url, form));
}

/** The server's JSON body of a failed request (ApiError keeps axios's error as cause). */
function errorData(error) {
  return error?.response?.data ?? error?.cause?.response?.data ?? null;
}

/** First server message for a failed request (validation or other), else the fallback. */
export function errorText(error, fallback) {
  const data = errorData(error);
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return first || data?.message || fallback;
}

/** Field -> first message, from a 422 response. */
export function fieldErrors(error) {
  const errors = errorData(error)?.errors ?? {};
  return Object.fromEntries(Object.entries(errors).map(([key, list]) => [key, Array.isArray(list) ? list[0] : String(list)]));
}
