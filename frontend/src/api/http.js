import client from './client';

const USE_MOCK = import.meta.env.VITE_USE_MOCK === 'true';

/**
 * Tries the real Laravel endpoint. Falls back to mock data when
 * VITE_USE_MOCK=true (local frontend-only dev) or when the request fails
 * because the backend controller doesn't exist yet (404/501). Once every
 * endpoint in api/endpoints.js is implemented, set VITE_USE_MOCK=false and
 * remove the catch fallback if you'd rather surface real errors.
 */
export async function getWithFallback(url, mockValue, config) {
  if (USE_MOCK) return mockValue;
  try {
    const res = await client.get(url, config);
    return res.data;
  } catch (err) {
    if ([404, 501].includes(err.response?.status)) return mockValue;
    throw err;
  }
}

export async function mutateWithFallback(method, url, body, mockValue) {
  if (USE_MOCK) return mockValue;
  try {
    const res = await client.request({ method, url, data: body });
    return res.data;
  } catch (err) {
    if ([404, 501].includes(err.response?.status)) return mockValue;
    throw err;
  }
}
