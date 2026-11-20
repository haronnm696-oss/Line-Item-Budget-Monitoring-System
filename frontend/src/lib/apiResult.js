// Honest API results: a failed request is always an error.
//
// A write (POST/PUT/PATCH/DELETE) that fails for any reason (404, any other
// 4xx, 5xx or no network) is never treated as success and never returns
// sample data. Pages show SAVE_ERROR and keep their data unchanged.

export const SAVE_ERROR = 'Could not save. Please try again.';
export const LOAD_ERROR = 'Could not load this data. Please try again.';

export class ApiError extends Error {
  constructor(message, { status = null, fieldErrors = {}, cause = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.fieldErrors = fieldErrors;
    this.cause = cause;
  }
}

function toApiError(error, message) {
  if (error instanceof ApiError) return error;
  return new ApiError(message, {
    status: error?.response?.status ?? null,
    fieldErrors: error?.response?.data?.errors ?? {},
    cause: error,
  });
}

function isSuccess(response) {
  return Boolean(response) && Number(response.status) >= 200 && Number(response.status) < 300;
}

/** Runs a write request; resolves with the server's data only on a 2xx response. */
export async function performWrite(send) {
  let response;
  try {
    response = await send();
  } catch (error) {
    throw toApiError(error, SAVE_ERROR);
  }
  if (!isSuccess(response)) throw new ApiError(SAVE_ERROR, { status: response?.status ?? null });
  return response.data;
}

/** Runs a read request; resolves with the server's data only on a 2xx response. */
export async function performRead(send) {
  let response;
  try {
    response = await send();
  } catch (error) {
    throw toApiError(error, LOAD_ERROR);
  }
  if (!isSuccess(response)) throw new ApiError(LOAD_ERROR, { status: response?.status ?? null });
  return response.data;
}

/**
 * The message to show for a failed request. A validation (4xx) response
 * gives its first field message or its message; a server error (5xx: a
 * crash, a database error) never shows the server's text ("Server Error",
 * or SQL when debug is on), only `fallback`. No response at all: `fallback`.
 */
export function failureMessage(data, status, fallback) {
  if (status >= 500) return fallback;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return first || data?.message || fallback;
}
