import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { fetchProgramApprovals } from '../api/program';

const ProgramRefreshContext = createContext({
  pendingCount: 0,
  refreshPending: () => {},
});

export function useProgramRefresh() {
  return useContext(ProgramRefreshContext);
}

const stroke = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
};

const ICONS = {
  dashboard: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <rect x="3" y="3" width="7" height="7" rx="1.6" />
      <rect x="14" y="3" width="7" height="7" rx="1.6" />
      <rect x="3" y="14" width="7" height="7" rx="1.6" />
      <rect x="14" y="14" width="7" height="7" rx="1.6" />
    </svg>
  ),
  projects: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H9l2 2.5h7.5A2.5 2.5 0 0 1 21 10v7a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17z" />
    </svg>
  ),
  approvals: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <rect x="4" y="4" width="16" height="16" rx="3" />
      <path d="m8 12 2.6 2.6L16 9" />
    </svg>
  ),
  reports: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M6 3h8l4 4v14H6z" />
      <path d="M14 3v4h4M9 12h6M9 16h6" />
    </svg>
  ),
  audit: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </svg>
  ),
  users: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <circle cx="9" cy="8" r="3.2" />
      <path d="M3.5 20a5.5 5.5 0 0 1 11 0M17 11a3 3 0 1 0 0-6M21 20a4.6 4.6 0 0 0-3-4.3" />
    </svg>
  ),
  menu: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M4 7h16M4 12h16M4 17h16" />
    </svg>
  ),
  bell: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6" />
      <path d="M10 19a2.2 2.2 0 0 0 4 0" />
    </svg>
  ),
  logout: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M10 8l-4 4 4 4M6 12h10" />
    </svg>
  ),
};

const NAV_GROUPS = [
  {
    label: 'Portfolio',
    items: [
      { to: '/program-dashboard', label: 'Overview', icon: 'dashboard' },
      { to: '/program-projects', label: 'Projects', icon: 'projects' },
    ],
  },
  {
    label: 'Review',
    items: [
      { to: '/program-approvals', label: 'Approvals', icon: 'approvals', badge: true },
      { to: '/program-reports', label: 'Reports', icon: 'reports' },
      { to: '/program-audit', label: 'Audit trail', icon: 'audit' },
    ],
  },
  {
    label: 'Administration',
    items: [{ to: '/program-users', label: 'Users and roles', icon: 'users' }],
  },
];

function todayLabel() {
  return new Date().toLocaleDateString('en-PH', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  });
}

export default function ProgramLayout() {
  const { user, roleInfo, signOut } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);
  const [pendingCount, setPendingCount] = useState(0);

  const refreshPending = useCallback(async () => {
    try {
      const payload = await fetchProgramApprovals({ status: 'pending' });
      setPendingCount(Number(payload?.counts?.pending) || 0);
    } catch {
      // The badge is a convenience; a failed count must not break the shell.
    }
  }, []);

  useEffect(() => {
    refreshPending();
  }, [refreshPending]);

  useEffect(() => {
    setMenuOpen(false);
  }, [location.pathname]);

  async function handleSignOut() {
    await signOut();
    navigate('/login', { replace: true });
  }

  const initials = user?.avatar_initials ?? user?.name?.slice(0, 2)?.toUpperCase() ?? 'U';

  return (
    <ProgramRefreshContext.Provider value={{ pendingCount, refreshPending }}>
      <div className="program-shell">
        {menuOpen && (
          <div
            className="program-backdrop"
            role="presentation"
            onClick={() => setMenuOpen(false)}
          />
        )}

        <aside className={`program-sidebar${menuOpen ? ' open' : ''}`} aria-label="Program Leader navigation">
          <button
            type="button"
            className="program-sidebar-close"
            onClick={() => setMenuOpen(false)}
            aria-label="Close menu"
          >
            ×
          </button>

          <div className="program-brand">
            <span className="program-seal" aria-hidden="true">MSU</span>
            <div>
              <div className="program-brand-name">LIB Monitoring</div>
              <div className="program-brand-sub">MSU–Main Campus</div>
            </div>
          </div>

          <nav className="program-nav">
            {NAV_GROUPS.map((group) => (
              <div className="program-nav-group" key={group.label}>
                <div className="program-nav-label">{group.label}</div>
                {group.items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    className={({ isActive }) => (isActive ? 'active' : '')}
                  >
                    <span className="program-nav-ic">{ICONS[item.icon]}</span>
                    <span>{item.label}</span>
                    {item.badge && pendingCount > 0 && (
                      <span className="program-nav-badge" aria-label={`${pendingCount} pending approvals`}>
                        {pendingCount}
                      </span>
                    )}
                  </NavLink>
                ))}
              </div>
            ))}
          </nav>

          <div className="program-nav-foot">
            <span className="program-avatar" aria-hidden="true">{initials}</span>
            <div>
              <b>{user?.name ?? 'Program Leader'}</b>
              <span>{roleInfo?.label ?? 'Program Leader'}</span>
            </div>
            <button
              type="button"
              className="program-signout"
              title="Sign out"
              aria-label="Sign out"
              onClick={handleSignOut}
            >
              {ICONS.logout}
            </button>
          </div>
        </aside>

        <div className="program-main">
          <header className="program-topbar">
            <button
              type="button"
              className="program-icon-btn program-menu-btn"
              onClick={() => setMenuOpen(true)}
              aria-label="Open menu"
              aria-expanded={menuOpen}
            >
              {ICONS.menu}
            </button>

            <span className="program-topbar-title">Program: All Projects — MSU–Main Campus</span>
            <span className="spacer" />

            <span className="program-topbar-date">
              <span className="program-live-dot" aria-hidden="true" />
              {todayLabel()}
            </span>

            <button
              type="button"
              className="program-icon-btn"
              title="Notifications"
              aria-label={`Notifications${pendingCount ? `, ${pendingCount} pending approvals` : ''}`}
              onClick={() => navigate('/program-approvals')}
            >
              {ICONS.bell}
              {pendingCount > 0 && <span className="program-icon-dot" aria-hidden="true" />}
            </button>

            <div className="program-user-chip">
              <span className="program-avatar" aria-hidden="true">{initials}</span>
              <div>
                <b>{user?.name ?? 'Program Leader'}</b>
                <span>{roleInfo?.label ?? 'Program Leader'}</span>
              </div>
            </div>

            <button
              type="button"
              className="program-icon-btn program-signout-top"
              title="Sign out"
              aria-label="Sign out"
              onClick={handleSignOut}
            >
              {ICONS.logout}
            </button>
          </header>

          <main className="program-content">
            <Outlet />
          </main>
        </div>
      </div>
    </ProgramRefreshContext.Provider>
  );
}
