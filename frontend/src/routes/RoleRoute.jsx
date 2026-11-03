import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { ROUTE_BY_ROLE } from '../pages/Dashboard';

/**
 * Keeps a route inside one or more roles. Users who are signed in but do not
 * hold an allowed role are sent to their own dashboard instead of seeing a
 * blank 403 screen. Authentication itself still belongs to ProtectedRoute.
 */
export default function RoleRoute({ allow = [], children }) {
  const { user, checking } = useAuth();

  if (checking) return <div className="center-fill">Loading…</div>;
  if (!user) return <Navigate to="/login" replace />;

  if (allow.length && !allow.includes(user.role)) {
    return <Navigate to={ROUTE_BY_ROLE[user.role] ?? '/dashboard'} replace />;
  }

  return children;
}
