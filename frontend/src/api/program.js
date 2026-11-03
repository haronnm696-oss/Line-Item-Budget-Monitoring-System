import client from './client';
import { ENDPOINTS } from './endpoints';

/**
 * Program-wide dashboard payload for the authenticated Program Leader.
 * `funder` is a funding agency id or acronym; empty means all projects.
 */
export async function fetchProgramOverview(funder = '') {
  const response = await client.get(ENDPOINTS.programOverview, {
    params: funder ? { funder } : undefined,
  });
  return response.data;
}

/**
 * Record an approval decision. Rejections require remarks; the backend
 * enforces that rule as well.
 */
export async function decideApproval(id, { decision, remarks = '' }) {
  const response = await client.post(ENDPOINTS.programDecision(id), {
    decision,
    remarks: remarks || null,
  });
  return response.data;
}
