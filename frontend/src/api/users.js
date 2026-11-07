import client from './client';
import { ENDPOINTS } from './endpoints';

const USE_MOCK = import.meta.env.VITE_USE_MOCK === 'true';

export async function fetchPendingUsers() {
  if (USE_MOCK) return [];
  const res = await client.get(ENDPOINTS.pendingUsers);
  return res.data?.users || [];
}

export async function approveUser(id, role) {
  if (USE_MOCK) {
    return { message: 'Mock mode: approved locally.', user: { id, role } };
  }
  const res = await client.patch(ENDPOINTS.approveUser(id), { role });
  return res.data;
}
