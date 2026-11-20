import { useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { useApiData } from '../lib/useApiData';
import { pickProject } from '../lib/myProjects';
import { apiGet } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import RoleShell from './RoleShell';
import { RoleBadgesContext } from './roleBadges';
import { ROLE_NAV, navItemFor } from './roleNav';

const BADGE_LABEL = {
  submissions: (count) => `${count} waiting for your review`,
  requests: (count) => `${count} request${count === 1 ? '' : 's'} decided since you last looked`,
  'my-submissions': (count) => `${count} returned to you`,
  verification: (count) => `${count} waiting for verification`,
};

/** Which badge endpoint each role uses, and how its counts map to nav badges. */
const BADGE_SOURCE = {
  PL: {
    role: 'project-leader',
    url: ENDPOINTS.leaderBadges,
    read: (data) => ({ submissions: Number(data?.submissions) || 0, requests: Number(data?.requests) || 0 }),
  },
  // Finance/Admin: Verification queue = "To verify" (same query as the Overview KPI).
  FIN: {
    role: 'finance-admin',
    url: ENDPOINTS.financeBadges,
    read: (data) => ({ verification: Number(data?.verification) || 0 }),
  },
  // Research Assistant: My submissions = "Returned to you" (same query as the Overview KPI).
  RA: {
    role: 'research-assistant',
    url: ENDPOINTS.assistantBadges,
    read: (data) => ({ 'my-submissions': Number(data?.my_submissions) || 0 }),
  },
};

/**
 * Sidebar badges for the project the page shows (?project=, else the
 * user's first project in that role). Project Leader: Submissions = "To
 * review", My requests = decisions not seen yet. Research Assistant: My
 * submissions = "Returned to you". Finance/Admin: Verification queue =
 * "To verify". Reloaded on every navigation and when a
 * page calls refreshBadges(). A failed count never breaks the shell.
 */
function useProjectBadges(code) {
  const source = BADGE_SOURCE[code] ?? null;
  const location = useLocation();
  const projects = useApiData(source ? ENDPOINTS.projects : null);
  const requested = new URLSearchParams(location.search).get('project');
  const projectId = source ? pickProject(projects.data, requested, source.role).project?.id ?? null : null;
  const [badges, setBadges] = useState({});
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!projectId || !source) return undefined;
    let alive = true;
    apiGet(source.url(projectId))
      .then((data) => { if (alive) setBadges(source.read(data)); })
      .catch(() => {});
    return () => { alive = false; };
  }, [projectId, source, location.pathname, location.search, tick]);

  const refreshBadges = useCallback(() => setTick((value) => value + 1), []);
  const take = useCallback((counts) => setBadges((current) => ({ ...current, ...counts })), []);

  return { badges: projectId ? badges : {}, refreshBadges, setBadges: take };
}

/**
 * Shell for the Project Leader, Research Assistant, Finance/Admin and
 * Researcher pages: the same shell as the Program Leader, with that role's
 * menu. The Project Leader and Research Assistant have badge counts.
 */
export default function RoleLayout() {
  const { user, roleInfo } = useAuth();
  const location = useLocation();
  const code = normalizeRole(user) ?? 'RES';
  const roleLabel = roleInfo?.label ?? 'Account';
  const current = navItemFor(code, location.pathname);
  const badgeState = useProjectBadges(code);

  return (
    <RoleBadgesContext.Provider value={badgeState}>
      <RoleShell
        navGroups={ROLE_NAV[code] ?? ROLE_NAV.RES}
        navLabel={`${roleLabel} navigation`}
        breadcrumb={current ? `${roleLabel}: ${current.label}` : `${roleLabel} — MSU–Main Campus`}
        badges={badgeState.badges}
        badgeLabel={(key, count) => (BADGE_LABEL[key] ?? ((n) => `${n}`))(count)}
        fallbackName={roleLabel}
      />
    </RoleBadgesContext.Provider>
  );
}
