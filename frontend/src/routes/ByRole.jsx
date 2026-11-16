import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';

/**
 * One path, a page per role: <ByRole RA={…} FIN={…} other={…} /> renders the
 * page named by the user's role code, else `other`.
 */
export default function ByRole({ other = null, ...pages }) {
  const { user } = useAuth();
  return pages[normalizeRole(user)] ?? other;
}
