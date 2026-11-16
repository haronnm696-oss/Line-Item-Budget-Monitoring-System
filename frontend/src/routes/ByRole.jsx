import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';

/** One path, two pages: the Research Assistant's own page, else the shared one. */
export default function ByRole({ RA, other }) {
  const { user } = useAuth();
  return normalizeRole(user) === 'RA' ? RA : other;
}
