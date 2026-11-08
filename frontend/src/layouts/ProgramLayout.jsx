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

const NAV = [
  { to: '/program-dashboard', label: 'Dashboard' },
  { to: '/program-projects', label: 'Projects' },
  { to: '/program-approvals', label: 'Approvals', badge: true },
  { to: '/program-reports', label: 'Reports' },
  { to: '/program-audit', label: 'Audit trail' },
  { to: '/program-users', label: 'Users and roles' },
];

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
            {NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                className={({ isActive }) => (isActive ? 'active' : '')}
              >
                <span>{item.label}</span>
                {item.badge && pendingCount > 0 && (
                  <span className="program-nav-badge" aria-label={`${pendingCount} pending approvals`}>
                    {pendingCount}
                  </span>
                )}
              </NavLink>
            ))}
          </nav>

          <div className="program-nav-foot">
            <span className="program-avatar" aria-hidden="true">{initials}</span>
            <div>
              <b>{user?.name ?? 'Program Leader'}</b>
              <span>{roleInfo?.label ?? 'Program Leader'}</span>
            </div>
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
              ☰
            </button>
            <span className="program-topbar-title">Program: All Projects — MSU–Main Campus</span>
            <span className="spacer" />
            <span className="program-pill amber">{roleInfo?.label ?? 'Program Leader'}</span>
            <button
              type="button"
              className="program-icon-btn"
              title="Notifications"
              aria-label={`Notifications${pendingCount ? `, ${pendingCount} pending approvals` : ''}`}
              onClick={() => navigate('/program-approvals')}
            >
              🔔
            </button>
            <button
              type="button"
              className="program-icon-btn"
              title="Sign out"
              aria-label="Sign out"
              onClick={handleSignOut}
            >
              ⏻
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
