import { useCallback, useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { useApiData } from '../lib/useApiData';
import { pickProject, projectRoleFor } from '../lib/myProjects';
import {
  hasTopBarPicker, readProjectChoice, requestedProject, searchWithProject, showProjectPicker, withProject, writeProjectChoice,
} from '../lib/projectChoice';
import { apiGet } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import RoleShell from './RoleShell';
import TopBarProjectPicker from './TopBarProjectPicker';
import { RoleBadgesContext } from './roleBadges';
import { ROLE_NAV } from './roleNav';

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
 * The project the role's pages show: ?project=, else the one remembered
 * from the top-bar picker (Research Assistant, Finance/Admin, Researcher),
 * else the first one (lib/useAssignedProject.js uses the same rule). A
 * valid ?project= that arrives by link is remembered too. The Project
 * Leader leads one project and has no picker.
 */
function useRoleProject(code) {
  const { user } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const role = projectRoleFor(code);
  const withPicker = hasTopBarPicker(code);
  const needsProjects = withPicker || Boolean(BADGE_SOURCE[code]);
  const projects = useApiData(needsProjects ? ENDPOINTS.projects : null);
  const fromUrl = new URLSearchParams(location.search).get('project');
  const remembered = withPicker ? readProjectChoice(user?.id, role) : null;
  const picked = needsProjects ? pickProject(projects.data, requestedProject(fromUrl, remembered), role) : { projects: [], project: null };
  const projectId = picked.project?.id ?? null;

  useEffect(() => {
    if (withPicker && fromUrl && projectId && String(projectId) === String(fromUrl)) {
      writeProjectChoice(user?.id, role, projectId);
    }
  }, [withPicker, fromUrl, projectId, user?.id, role]);

  function choose(id) {
    writeProjectChoice(user?.id, role, id);
    navigate({ pathname: location.pathname, search: searchWithProject(location.search, id) }, { replace: true });
  }

  return { projects: picked.projects, projectId, choose, showPicker: showProjectPicker(code, picked.projects), withPicker };
}

/**
 * Sidebar badges for the project the page shows. Project Leader:
 * Submissions = "To review", My requests = decisions not seen yet. Research
 * Assistant: My submissions = "Returned to you". Finance/Admin: Verification
 * queue = "To verify". Reloaded on every navigation and when a page calls
 * refreshBadges(). A failed count never breaks the shell.
 */
function useProjectBadges(code, projectId) {
  const source = BADGE_SOURCE[code] ?? null;
  const location = useLocation();
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

  return { badges: projectId && source ? badges : {}, refreshBadges, setBadges: take };
}

/**
 * Shell for the Project Leader, Research Assistant, Finance/Admin and
 * Researcher pages: the same shell as the Program Leader, with that role's
 * menu. The Project Leader, Research Assistant and Finance/Admin have
 * badge counts. The Research Assistant, Finance/Admin and Researcher choose
 * their project once, in the top bar (shown with more than one project).
 */
export default function RoleLayout() {
  const { user, roleInfo } = useAuth();
  const code = normalizeRole(user) ?? 'RES';
  const roleLabel = roleInfo?.label ?? 'Account';
  const current = useRoleProject(code);
  const badgeState = useProjectBadges(code, current.projectId);

  return (
    <RoleBadgesContext.Provider value={badgeState}>
      <RoleShell
        navGroups={ROLE_NAV[code] ?? ROLE_NAV.RES}
        navLabel={`${roleLabel} navigation`}
        badges={badgeState.badges}
        badgeLabel={(key, count) => (BADGE_LABEL[key] ?? ((n) => `${n}`))(count)}
        fallbackName={roleLabel}
        navTo={current.withPicker ? (to) => withProject(to, current.projectId) : undefined}
        topBarExtra={current.showPicker
          ? <TopBarProjectPicker projects={current.projects} projectId={current.projectId} onChoose={current.choose} />
          : null}
      />
    </RoleBadgesContext.Provider>
  );
}
