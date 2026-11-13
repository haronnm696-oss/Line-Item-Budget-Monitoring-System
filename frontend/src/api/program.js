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
 * Projects page rows. Built by the same backend code as the overview, so
 * spent %, health and the health reason always match.
 */
export async function fetchProgramProjects(funder = '') {
  const response = await client.get(ENDPOINTS.programProjects, {
    params: funder ? { funder } : undefined,
  });
  return response.data;
}

/** Read-only project detail: header, line items, tranches, expenses, team, activity. */
export async function fetchProgramProject(id) {
  const response = await client.get(ENDPOINTS.programProject(id));
  return response.data;
}

/**
 * Approval workspace, filtered by tab, type, project and funder.
 */
export async function fetchProgramApprovals(params = {}) {
  const response = await client.get(ENDPOINTS.programApprovals, {
    params: Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== '' && value != null && value !== 'all')
    ),
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

export async function fetchPendingRegistrations() {
  const response = await client.get(ENDPOINTS.programRegistrations);
  return response.data;
}

export async function decideRegistration(id, { decision, role = '', remarks = '' }) {
  const response = await client.post(ENDPOINTS.programRegistrationDecision(id), {
    decision,
    role: role || null,
    remarks: remarks || null,
  });
  return response.data;
}

export async function fetchProgramUsers(projectId = '') {
  const response = await client.get(ENDPOINTS.programUsers, {
    params: projectId ? { project_id: projectId } : undefined,
  });
  return response.data;
}

export async function createProgramUser(payload) {
  const response = await client.post(ENDPOINTS.programUsers, payload);
  return response.data;
}

export async function updateProgramUserStatus(id, payload) {
  const response = await client.patch(ENDPOINTS.programUserStatus(id), payload);
  return response.data;
}

export async function updateProgramUserRole(id, payload) {
  const response = await client.patch(ENDPOINTS.programUserRole(id), payload);
  return response.data;
}

export async function assignToProject(payload) {
  const response = await client.post(ENDPOINTS.programAssignments, payload);
  return response.data;
}

export async function updateProjectAssignment(userId, projectId, role, reason = null) {
  const response = await client.patch(ENDPOINTS.programAssignment(userId, projectId), { role, reason });
  return response.data;
}

export async function removeProjectAssignment(userId, projectId, reason = null) {
  const response = await client.delete(ENDPOINTS.programAssignment(userId, projectId), { data: { reason } });
  return response.data;
}
