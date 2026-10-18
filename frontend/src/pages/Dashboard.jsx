import { useAuth } from '../context/AuthContext';
import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { normalizeRole } from '../data/mockData';

// Post-login role routing. Each role lands on its own dashboard URL so the
// address bar (and direct links) reflect the user's module.
const ROUTE_BY_ROLE = {
  PRG: '/dashboard/program-leader',    // Program Dashboard
  PL:  '/dashboard/project-leader',    // Project Budget Dashboard
  RA:  '/dashboard/research-assistant',// Data Entry / Payroll Module
  RES: '/dashboard/researcher',        // Budget Analytics View
  FIN: '/dashboard/finance',
};

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
