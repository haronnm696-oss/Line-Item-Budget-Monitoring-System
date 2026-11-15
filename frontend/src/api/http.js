import client from './client';
import { performRead, performWrite } from '../lib/apiResult';

/**
 * Reads from the API. A failed request rejects with an ApiError ("Could not
 * load this data. Please try again.") — there is no sample-data fallback.
 */
export function apiGet(url, config) {
  return performRead(() => client.get(url, config));
}

/**
 * Writes to the API. Any failure (404, other 4xx, 5xx, network) rejects with
 * an ApiError ("Could not save. Please try again."); a failed write is never
 * reported as saved and never returns sample data.
 */
export function apiWrite(method, url, body) {
  return performWrite(() => client.request({ method, url, data: body }));
}
