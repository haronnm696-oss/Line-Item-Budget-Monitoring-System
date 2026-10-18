import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function ProtectedRoute() {
  const { user, checking } = useAuth();

  if (checking) return <div className="center-fill">Loading…</div>;
  if (!user) return <Navigate to="/login" replace />;

  return <Outlet />;
}
