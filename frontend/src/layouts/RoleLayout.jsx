import { useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../data/mockData';
import RoleShell from './RoleShell';
import { BELL_TARGET, ROLE_NAV, navItemFor } from './roleNav';

/**
 * Shell for the Project Leader, Research Assistant, Finance/Admin and
 * Researcher pages: the same shell as the Program Leader, with that role's
 * menu. Badge counts are empty for now.
 */
export default function RoleLayout() {
  const { user, roleInfo } = useAuth();
  const location = useLocation();
  const code = normalizeRole(user) ?? 'RES';
  const roleLabel = roleInfo?.label ?? 'Account';
  const current = navItemFor(code, location.pathname);

  return (
    <RoleShell
      navGroups={ROLE_NAV[code] ?? ROLE_NAV.RES}
      navLabel={`${roleLabel} navigation`}
      breadcrumb={current ? `${roleLabel}: ${current.label}` : `${roleLabel} — MSU–Main Campus`}
      badges={{}}
      bell={{ count: 0, to: BELL_TARGET[code] ?? '/dashboard', label: '' }}
      fallbackName={roleLabel}
    />
  );
}
