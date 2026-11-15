import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { homeFor, rolesFor } from './access';

/**
 * Keeps a route inside one or more roles. Users who are signed in but do not
 * hold an allowed role are sent to their own dashboard instead of seeing a
 * blank 403 screen. Authentication itself still belongs to ProtectedRoute.
 */
export default function RoleRoute({ allow, path, children }) {
  const { user, checking } = useAuth();

  if (checking) return <div className="center-fill">Loading…</div>;
  if (!user) return <Navigate to="/login" replace />;

  // Roles come from an explicit list or from the route table (routes/access.js).
  const roles = allow ?? rolesFor(path ?? '');
  if (!roles.includes(user.role)) {
    return <Navigate to={homeFor(user.role)} replace />;
  }

  return children;
}
