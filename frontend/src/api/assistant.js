import { ENDPOINTS } from './endpoints';
import client from './client';
import { apiGet } from './http';
import { performWrite } from '../lib/apiResult';

const DOCUMENTS_URL = {
  expense: ENDPOINTS.expenseDocuments,
  payroll: ENDPOINTS.payrollBatchDocuments,
  purchase_request: ENDPOINTS.purchaseRequestDocuments,
};

/** Upload one file to a record (expense | payroll | purchase_request). */
export function uploadDocument(type, id, file, documentType) {
  const body = new FormData();
  body.append('file', file);
  body.append('document_type', documentType);
  return performWrite(() => client.post(DOCUMENTS_URL[type](id), body));
}

/** One of the user's records, any stage (side panel). */
export function fetchMyItem(projectId, type, id) {
  return apiGet(ENDPOINTS.mySubmission(projectId, type, id));
}
