import { useAuth } from '../../context/AuthContext';

/**
 * PLACEHOLDER — Research Assistant dashboard (Data Entry / Payroll Module).
 * Replace this stub with real data-entry and payroll entry views.
 */
export default function ResearchAssistantDashboard() {
  const { user } = useAuth();
  return (
    <div className="dashboard research-assistant-dashboard">
      <h2>Data Entry / Payroll Module</h2>
      <p>Welcome, {user?.name} — Research Assistant.</p>
      <p className="placeholder">
        RA data entry / payroll placeholder. Implement expense logging and
        payroll entry views here.
      </p>
    </div>
  );
}
