import { useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Filters kept in the URL query. `update({ tab: 'returned', open: '' })`
 * sets or removes keys (a value equal to its default in `defaults`, or
 * empty, is removed). Opening / closing a panel (`open`) adds a history
 * entry so Back closes it; other changes replace the entry.
 */
export function useUrlQuery(defaults = {}) {
  const [searchParams, setSearchParams] = useSearchParams();

  const update = useCallback((changes) => {
    const next = new URLSearchParams(window.location.search);
    Object.entries(changes).forEach(([key, value]) => {
      if (value === '' || value == null || defaults[key] === value) next.delete(key);
      else next.set(key, String(value));
    });
    setSearchParams(next, { replace: !Object.prototype.hasOwnProperty.call(changes, 'open') });
    // defaults is a constant object per page.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setSearchParams]);

  const get = (key) => searchParams.get(key) ?? defaults[key] ?? '';

  return { searchParams, get, update };
}
