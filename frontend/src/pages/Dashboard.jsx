import { useAuth } from '../context/AuthContext';
import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { normalizeRole } from '../lib/roles';
import { ROUTE_BY_ROLE } from '../routes/access';

// Post-login role routing: each role lands on its own dashboard URL
// (ROUTE_BY_ROLE in routes/access.js).

export default function Dashboard() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const code = normalizeRole(user);

  useEffect(() => {
    if (!code) return;
    navigate(ROUTE_BY_ROLE[code] ?? '/dashboard', { replace: true });
  }, [code, navigate]);

  return null;
}
