import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { CURRENT_PROJECT } from '../data/mockData';

export default function Topbar() {
  const { user, roleInfo, signOut } = useAuth();
  const navigate = useNavigate();

  const isProgram = user?.role === 'PRG';

  async function handleSignOut() {
    await signOut();
    navigate('/login', { replace: true });
  }

  return (
    <div className="topbar">
      <div className="proj-select">
        {isProgram ? 'Program: ' : 'Project: '}
        <b>{isProgram ? 'All Projects — MSU–Main Campus' : CURRENT_PROJECT.title}</b> ▾
      </div>
      <div className="spacer" />
      <span className="role-chip">{roleInfo?.label ?? user?.roleLabel ?? 'No role'}</span>
      <button className="icon-btn" title="Notifications">🔔</button>
      <button className="icon-btn" title="Sign out" onClick={handleSignOut}>⏻</button>
    </div>
  );
}
