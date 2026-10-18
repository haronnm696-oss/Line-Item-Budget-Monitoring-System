import { useAuth } from '../../context/AuthContext';

/**
 * PLACEHOLDER — Researcher dashboard (Budget Analytics View).
 * Replace this stub with real budget analytics views.
 */
export default function ResearcherDashboard() {
  const { user } = useAuth();
  return (
    <div className="dashboard researcher-dashboard">
      <h2>Budget Analytics View</h2>
      <p>Welcome, {user?.name} — Researcher.</p>
      <p className="placeholder">
        Researcher budget analytics placeholder. Implement analytics views
        here.
      </p>
    </div>
  );
}
