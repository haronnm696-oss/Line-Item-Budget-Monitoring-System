import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { formatInManila } from '../lib/datetime';
import PageErrorBoundary from '../components/program/PageErrorBoundary';

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
  budget: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <rect x="3.5" y="4" width="17" height="16" rx="2.5" />
      <path d="M3.5 9h17M8 13h3M8 16.5h3M14 13h2.5M14 16.5h2.5" />
    </svg>
  ),
  transactions: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M4 8h13l-3.5-3.5M20 16H7l3.5 3.5" />
    </svg>
  ),
  requests: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M20.5 3.5 10 14M20.5 3.5 14 20.5l-4-6.5-6.5-4z" />
    </svg>
  ),
  receipt: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M6 3h12v18l-3-2-3 2-3-2-3 2z" />
      <path d="M9 8h6M9 12h6M9 16h3" />
    </svg>
  ),
  cart: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M3 4h2.5l2.2 11h10.6l2-8H7" />
      <circle cx="9.5" cy="19" r="1.4" />
      <circle cx="17" cy="19" r="1.4" />
    </svg>
  ),
  payroll: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <rect x="3" y="6" width="18" height="12" rx="2.5" />
      <circle cx="12" cy="12" r="2.6" />
      <path d="M6.5 9.5v5M17.5 9.5v5" />
    </svg>
  ),
  documents: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M8 3h7l4 4v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
      <path d="M15 3v4h4M4 7v12a2 2 0 0 0 2 2h9" />
    </svg>
  ),
  submissions: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M4 13h4.5l1.5 2.5h4l1.5-2.5H20" />
      <path d="M5.5 6.5 4 13v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5l-1.5-6.5A2 2 0 0 0 16.6 5H7.4a2 2 0 0 0-1.9 1.5z" />
    </svg>
  ),
  balance: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M12 4v16M7 20h10M5 7h14" />
      <path d="M5 7 2.5 13a2.8 2.8 0 0 0 5 0zM19 7l-2.5 6a2.8 2.8 0 0 0 5 0z" />
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
  help: (
    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" />
      <path d="M9.5 9.2a2.6 2.6 0 0 1 5 .9c0 1.7-2.5 2.3-2.5 3.9M12 17h.01" />
    </svg>
  ),
  chevron: (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M6 9l6 6 6-6" />
    </svg>
  ),
  chevronLeft: (
    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M15 6l-6 6 6 6" />
    </svg>
  ),
  chevronRight: (
    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 6l6 6-6 6" />
    </svg>
  ),
};

const SIDEBAR_KEY = 'lib.sidebar.collapsed';

// Storage can be blocked (private mode, site settings); the toggle must
// still work for the session, so failures fall back silently.
function readCollapsed() {
  try {
    return window.localStorage.getItem(SIDEBAR_KEY) === '1';
  } catch {
    return false;
  }
}

function writeCollapsed(value) {
  try {
    window.localStorage.setItem(SIDEBAR_KEY, value ? '1' : '0');
  } catch {
    // Ignore: the in-memory state is enough.
  }
}

/** First letter of the first and last word of a name, e.g. "Norjannah Haron" -> "NH". */
function initialsFrom(name) {
  const words = String(name ?? '').trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return '';
  const first = words[0].charAt(0);
  const last = words.length > 1 ? words[words.length - 1].charAt(0) : '';
  return (first + last).toUpperCase();
}

function todayLabel() {
  return formatInManila(new Date(), { weekday: 'long', day: 'numeric', month: 'long' });
}

/**
 * The shared shell for every role: sidebar (270px, section labels, active
 * state, gold badge counts, Help & guide, Collapse with saved state, 76px
 * rail), header (breadcrumb, Philippine date, bell, user chip and menu) and
 * a per-page error boundary.
 *
 * @param {object} props
 * @param {Array} props.navGroups      [{ label?, items: [{ to, label, icon, badge? }] }]
 * @param {string} props.navLabel      aria-label of the sidebar
 * @param {string} props.breadcrumb    text at the left of the header
 * @param {Object<string, number>} props.badges  counts by item `badge` key
 * @param {(key: string, count: number) => string} props.badgeLabel
 * @param {{ count: number, to: string, label: string }} props.bell
 * @param {string} props.fallbackName  name shown before the user loads
 */
export default function RoleShell({ navGroups, navLabel, breadcrumb, badges = {}, badgeLabel, bell, fallbackName }) {
  const { user, roleInfo, signOut } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const profileRef = useRef(null);

  useEffect(() => {
    setMenuOpen(false);
    setProfileOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    if (!profileOpen) return undefined;

    const onPointerDown = (event) => {
      if (!profileRef.current?.contains(event.target)) setProfileOpen(false);
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') setProfileOpen(false);
    };

    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [profileOpen]);

  function toggleCollapsed() {
    const next = !collapsed;
    setCollapsed(next);
    writeCollapsed(next);
  }

  async function handleSignOut() {
    await signOut();
    navigate('/login', { replace: true });
  }

  const initials = initialsFrom(user?.name) || user?.avatar_initials || 'U';
  const roleLabel = roleInfo?.label ?? fallbackName;
  const bellCount = Number(bell?.count) || 0;

  return (
    <div className="program-shell">
      {menuOpen && (
        <div
          className="program-backdrop"
          role="presentation"
          onClick={() => setMenuOpen(false)}
        />
      )}

      <aside
        id="program-sidebar"
        className={`program-sidebar${menuOpen ? ' open' : ''}${collapsed ? ' is-collapsed' : ''}`}
        aria-label={navLabel}
      >
        <button
          type="button"
          className="program-sidebar-close"
          onClick={() => setMenuOpen(false)}
          aria-label="Close menu"
        >
          ×
        </button>

        <div className="program-brand">
          <img className="program-brand-seal" src="/images/msu-seal-outline.png" alt="MSU seal" />
          <div className="program-brand-text">
            <div className="program-brand-name">LIB Monitoring</div>
            <div className="program-brand-sub">MSU–Main Campus</div>
          </div>
        </div>

        <nav className="program-nav">
          {navGroups.map((group) => (
            <div className="program-nav-group" key={group.label ?? 'main'}>
              {group.label && (
                <div className="program-nav-label"><span>{group.label}</span></div>
              )}
              {group.items.map((item) => {
                const count = item.badge ? Number(badges[item.badge]) || 0 : 0;
                return (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    className={({ isActive }) => (isActive ? 'active' : '')}
                    title={collapsed ? item.label : undefined}
                    aria-label={collapsed ? item.label : undefined}
                  >
                    <span className="program-nav-ic">{ICONS[item.icon]}</span>
                    <span className="program-nav-text">{item.label}</span>
                    {count > 0 && (
                      <span className="program-nav-badge" aria-label={badgeLabel ? badgeLabel(item.badge, count) : `${count} to review`}>
                        {count}
                      </span>
                    )}
                  </NavLink>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="program-side-foot">
          <a
            className="program-help-link"
            href="#"
            title={collapsed ? 'Help & guide' : undefined}
            aria-label={collapsed ? 'Help & guide' : undefined}
          >
            {ICONS.help}
            <span className="program-help-text">Help &amp; guide</span>
          </a>

          <button
            type="button"
            className="program-collapse"
            onClick={toggleCollapsed}
            aria-expanded={!collapsed}
            aria-controls="program-sidebar"
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {collapsed ? ICONS.chevronRight : ICONS.chevronLeft}
            {!collapsed && <span className="program-collapse-text">Collapse</span>}
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

          <span className="program-topbar-title">{breadcrumb}</span>
          <span className="spacer" />

          <span className="program-topbar-date">
            <span className="program-live-dot" aria-hidden="true" />
            {todayLabel()}
          </span>

          <button
            type="button"
            className="program-icon-btn"
            title="Notifications"
            aria-label={`Notifications${bellCount ? `, ${bell.label}` : ''}`}
            onClick={() => navigate(bell?.to ?? '/dashboard')}
          >
            {ICONS.bell}
            {bellCount > 0 && <span className="program-icon-dot" aria-hidden="true" />}
          </button>

          <div className="program-user-menu" ref={profileRef}>
            <button
              type="button"
              className="program-user-chip"
              aria-haspopup="menu"
              aria-expanded={profileOpen}
              onClick={() => setProfileOpen((open) => !open)}
            >
              <span className="program-avatar" aria-hidden="true">{initials}</span>
              <span className="program-user-chip-text">
                <b>{user?.name ?? fallbackName}</b>
                <span>{roleLabel}</span>
              </span>
              <span className="program-user-caret" aria-hidden="true">{ICONS.chevron}</span>
            </button>

            {profileOpen && (
              <div className="program-user-dropdown" role="menu">
                <div className="program-user-dropdown-head">
                  <span className="program-avatar" aria-hidden="true">{initials}</span>
                  <div>
                    <b>{user?.name ?? fallbackName}</b>
                    <span>{user?.email ?? ''}</span>
                    <em>{roleLabel}</em>
                  </div>
                </div>
                <button
                  type="button"
                  className="program-user-menu-item danger"
                  role="menuitem"
                  onClick={handleSignOut}
                >
                  {ICONS.logout}
                  <span>Sign out</span>
                </button>
              </div>
            )}
          </div>
        </header>

        <main className="program-content">
          <PageErrorBoundary key={location.pathname}>
            <Outlet />
          </PageErrorBoundary>
        </main>
      </div>
    </div>
  );
}
