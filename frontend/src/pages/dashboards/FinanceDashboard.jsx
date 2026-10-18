import { useAuth } from '../../context/AuthContext';

/**
 * PLACEHOLDER — Finance/Admin dashboard.
 * Replace this stub with real finance views (verification, approvals,
 * reports, etc.).
 */
export default function FinanceDashboard() {
  const { user } = useAuth();
  return (
    <div className="dashboard finance-dashboard">
      <h2>Finance Dashboard</h2>
      <p>Welcome, {user?.name} — Finance/Admin Staff.</p>
      <p className="placeholder">
        Finance dashboard placeholder. Implement verification/approval views
        here.
      </p>
    </div>
  );
}
