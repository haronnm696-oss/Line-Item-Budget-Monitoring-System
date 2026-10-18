import { NavLink } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

// Which roles see each nav item — mirrors the capability matrix on the
// Users & roles screen. Keep these two in sync if roles change.
const NAV = [
  { section: 'Monitor', items: [
    { to: '/dashboard', icon: '▦', label: 'Dashboard', roles: ['PRG', 'PL', 'RES', 'RA', 'FIN'] },
    { to: '/projects', icon: '▤', label: 'Projects', roles: ['PRG', 'PL', 'RES', 'FIN'] },
  ]},
  { section: 'Transactions', items: [
    { to: '/expenses', icon: '₱', label: 'Expenses', roles: ['RA', 'PL', 'FIN'] },
    { to: '/payroll', icon: '▥', label: 'Payroll', roles: ['RA', 'PL', 'FIN'] },
  ]},
  { section: 'Oversight', items: [
    { to: '/reports', icon: '▨', label: 'Reports', roles: ['PRG', 'PL', 'RES', 'FIN'] },
    { to: '/audit', icon: '≡', label: 'Audit trail', roles: ['PRG', 'PL', 'FIN'] },
    { to: '/users', icon: '◉', label: 'Users & roles', roles: ['PRG', 'PL'] },
  ]},
];

export default function Sidebar() {
  const { user, roleInfo } = useAuth();

  if (!user) return null;

  return (
    <aside className="sidebar">
      <div className="brand">
        <span className="seal">MSU</span>
        <h1>LIB Monitoring System</h1>
        <p>MSU–Main Campus</p>
      </div>
      <nav className="nav" aria-label="Main navigation">
        {NAV.map((group) => {
          const visible = group.items.filter((i) => i.roles.includes(user.role));
          if (!visible.length) return null;
          return (
            <div key={group.section}>
              <h2>{group.section}</h2>
              {visible.map((item) => (
                <NavLink key={item.to} to={item.to} className={({ isActive }) => (isActive ? 'active' : '')}>
                  <span className="ic">{item.icon}</span>{item.label}
                </NavLink>
              ))}
            </div>
          );
        })}
      </nav>
      <div className="side-user">
        <div className="avatar">{user.avatar_initials ?? 'U'}</div>
        <div><b>{user.name}</b><span>{roleInfo?.label ?? user.roleLabel ?? user.role}</span></div>
      </div>
    </aside>
  );
}
