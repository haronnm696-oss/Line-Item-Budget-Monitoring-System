import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../data/mockData';
import { ROUTE_BY_ROLE } from '../pages/Dashboard';
import Landing from '../pages/Landing';

export default function HomeRoute() {
  const { user } = useAuth();
  const role = normalizeRole(user);

  if (user && role) {
    return <Navigate to={ROUTE_BY_ROLE[role] ?? '/dashboard'} replace />;
  }

  return <Landing />;
}
