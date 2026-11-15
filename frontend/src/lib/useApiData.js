import { useCallback, useEffect, useState } from 'react';
import { apiGet } from '../api/http';

/**
 * Loads one API URL with honest states: 'idle' (no URL yet), 'loading',
 * 'error' (with a message and retry) or 'ready'. Never returns sample data.
 * Each result is tagged with the URL and attempt it belongs to, so a result
 * for an old URL is never shown under a new one.
 */
export function useApiData(url) {
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState(null); // { key, data } | { key, error }
  const key = url ? `${url}#${attempt}` : '';

  useEffect(() => {
    if (!key) return undefined;
    let alive = true;
    apiGet(url)
      .then((data) => { if (alive) setResult({ key, data }); })
      .catch((error) => { if (alive) setResult({ key, error }); });
    return () => { alive = false; };
  }, [key, url]);

  const retry = useCallback(() => setAttempt((value) => value + 1), []);

  if (!key) return { status: 'idle', data: null, error: null, retry };
  if (result?.key !== key) return { status: 'loading', data: null, error: null, retry };
  if (result.error) return { status: 'error', data: null, error: result.error, retry };
  return { status: 'ready', data: result.data, error: null, retry };
}
