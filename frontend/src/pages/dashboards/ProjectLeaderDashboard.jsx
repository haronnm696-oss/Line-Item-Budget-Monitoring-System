import { useAuth } from '../../context/AuthContext';

/**
 * PLACEHOLDER — Project Leader dashboard.
 * Replace this stub with real project budget views (line-item budget,
 * expenses, payroll for the leader's projects, etc.).
 */
export default function ProjectLeaderDashboard() {
  const { user } = useAuth();
  return (
    <div className="dashboard project-leader-dashboard">
      <h2>Project Budget Dashboard</h2>
      <p>Welcome, {user?.name} — Project Leader.</p>
      <p className="placeholder">
        Project budget dashboard placeholder. Implement project-level LIB
        views here.
      </p>
    </div>
  );
}
