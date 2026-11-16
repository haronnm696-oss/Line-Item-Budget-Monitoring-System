import { useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { useApiData } from '../lib/useApiData';
import { pickProject } from '../lib/useAssignedProject';
import { apiGet } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import RoleShell from './RoleShell';
import { RoleBadgesContext } from './roleBadges';
import { ROLE_NAV, navItemFor } from './roleNav';

const BADGE_LABEL = {
  submissions: (count) => `${count} waiting for your review`,
  requests: (count) => `${count} request${count === 1 ? '' : 's'} decided since you last looked`,
};

/**
 * Project Leader badges for the project the page shows (?project=, else the
 * first project they lead): Submissions = the "To review" count, My
 * requests = decisions not seen yet. Reloaded on every navigation and when
 * a page calls refreshBadges(). A failed count never breaks the shell.
 */
function useLeaderBadges(enabled) {
  const location = useLocation();
  const projects = useApiData(enabled ? ENDPOINTS.projects : null);
  const requested = new URLSearchParams(location.search).get('project');
  const projectId = enabled ? pickProject(projects.data, requested, 'project-leader').project?.id ?? null : null;
  const [badges, setBadges] = useState({});
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!projectId) return undefined;
    let alive = true;
    apiGet(ENDPOINTS.leaderBadges(projectId))
      .then((data) => { if (alive) setBadges({ submissions: Number(data?.submissions) || 0, requests: Number(data?.requests) || 0 }); })
      .catch(() => {});
    return () => { alive = false; };
  }, [projectId, location.pathname, location.search, tick]);

  const refreshBadges = useCallback(() => setTick((value) => value + 1), []);
  const take = useCallback((counts) => setBadges((current) => ({ ...current, ...counts })), []);

  return { badges: projectId ? badges : {}, refreshBadges, setBadges: take };
}

/**
 * Shell for the Project Leader, Research Assistant, Finance/Admin and
 * Researcher pages: the same shell as the Program Leader, with that role's
 * menu. Only the Project Leader has badge counts so far.
 */
export default function RoleLayout() {
  const { user, roleInfo } = useAuth();
  const location = useLocation();
  const code = normalizeRole(user) ?? 'RES';
  const roleLabel = roleInfo?.label ?? 'Account';
  const current = navItemFor(code, location.pathname);
  const leader = useLeaderBadges(code === 'PL');

  return (
    <RoleBadgesContext.Provider value={leader}>
      <RoleShell
        navGroups={ROLE_NAV[code] ?? ROLE_NAV.RES}
        navLabel={`${roleLabel} navigation`}
        breadcrumb={current ? `${roleLabel}: ${current.label}` : `${roleLabel} — MSU–Main Campus`}
        badges={leader.badges}
        badgeLabel={(key, count) => (BADGE_LABEL[key] ?? ((n) => `${n}`))(count)}
        fallbackName={roleLabel}
      />
    </RoleBadgesContext.Provider>
  );
}
