import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { ROUTE_BY_ROLE } from './access';
import Landing from '../pages/Landing';

export default function HomeRoute() {
  const { user } = useAuth();
  const role = normalizeRole(user);

  if (user && role) {
    return <Navigate to={ROUTE_BY_ROLE[role] ?? '/dashboard'} replace />;
  }

  return <Landing />;
}
