import { useParams, useNavigate } from 'react-router-dom';
import Chip, { statusTone } from '../components/Chip';
import LoadState from '../components/LoadState';
import UtilBar from '../components/UtilBar';
import { formatPct, peso } from '../components/money';
import { ENDPOINTS } from '../api/endpoints';
import { useAuth } from '../context/AuthContext';
import { canOpen } from '../routes/access';
import { useApiData } from '../lib/useApiData';
import PersonnelSection from '../components/personnel/PersonnelSection';

function sum(rows, key) { return rows.reduce((t, r) => t + (Number(r[key]) || 0), 0); }
function pct(part, whole) { return formatPct(whole > 0 ? (part / whole) * 100 : 0); }

export default function ProjectDetail() {
  const { id } = useParams();
  // 403 for a project the user is not assigned to; shown as an error.
  const state = useApiData(ENDPOINTS.project(id));
  return (
    <LoadState state={state} loadingText="Loading project…">
      {state.data && <ProjectView data={state.data} />}
    </LoadState>
  );
}

function ProjectView({ data }) {
  const navigate = useNavigate();
  const { user } = useAuth();
  const project = data.project;
  const ps = Array.isArray(data.ps) ? data.ps : [];
  const mooe = Array.isArray(data.mooe) ? data.mooe : [];
  const psAllocated = sum(ps, 'allocated');
  const psSpent = sum(ps, 'spent');
  const mooeAllocated = sum(mooe, 'allocated');
  const mooeSpent = sum(mooe, 'spent');
  const totalAllocated = psAllocated + mooeAllocated;
  const totalSpent = psSpent + mooeSpent;

  return (
    <>
      <div className="page-head">
        <div>
          <h2>{project.title}</h2>
          <p><span className="num">{project.code}</span> · {project.agency} · {project.period} · <Chip tone={statusTone(project.status)}>{project.status}</Chip></p>
        </div>
      </div>

      <div className="tabs">
        <button className="active">LIB line items</button>
        {canOpen(user?.role, '/expenses') && (
          <button onClick={() => navigate(`/expenses?project=${project.id}`)}>Expenses</button>
        )}
        {canOpen(user?.role, '/payroll') && (
          <button onClick={() => navigate(`/payroll?project=${project.id}`)}>Payroll</button>
        )}
      </div>

      <div className="grid c2" style={{ marginBottom: 14 }}>
        <LibTable title="Personnel Services (PS)" hint={`${peso(psAllocated)} allocated`} rows={ps} />
        <LibTable title="Maintenance & Other Operating Expenses (MOOE)" hint={`${peso(mooeAllocated)} allocated`} rows={mooe} />
      </div>

      <div className="panel">
        <div className="panel-head"><h3>Project summary</h3></div>
        <div className="wide-scroll">
          <table>
            <thead><tr><th></th><th className="r">Allocated</th><th className="r">Spent</th><th className="r">Remaining</th><th className="r">Utilization</th></tr></thead>
            <tbody>
              <tr>
                <td className="mono-b">Personnel Services</td>
                <td className="r num">{peso(psAllocated)}</td>
                <td className="r num">{peso(psSpent)}</td>
                <td className="r num">{peso(psAllocated - psSpent)}</td>
                <td className="r num">{pct(psSpent, psAllocated)}</td>
              </tr>
              <tr>
                <td className="mono-b">MOOE</td>
                <td className="r num">{peso(mooeAllocated)}</td>
                <td className="r num">{peso(mooeSpent)}</td>
                <td className="r num">{peso(mooeAllocated - mooeSpent)}</td>
                <td className="r num">{pct(mooeSpent, mooeAllocated)}</td>
              </tr>
              <tr style={{ background: 'color-mix(in srgb, var(--gold) 8%, transparent)' }}>
                <td className="mono-b">Total</td>
                <td className="r num mono-b">{peso(totalAllocated)}</td>
                <td className="r num mono-b">{peso(totalSpent)}</td>
                <td className="r num mono-b">{peso(totalAllocated - totalSpent)}</td>
                <td className="r num mono-b">{pct(totalSpent, totalAllocated)}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      {/* Read-only for the Project Leader (Finance/Admin see it on Payroll).
          Researchers never get it (the API answers 403 as well). */}
      {user?.role === 'PL' && <div style={{ marginTop: 14 }}><PersonnelSection projectId={project.id} /></div>}
    </>
  );
}

function LibTable({ title, hint, rows }) {
  return (
    <div className="panel">
      <div className="panel-head"><h3>{title}</h3><span className="hint num">{hint}</span></div>
      <div className="wide-scroll">
        <table>
          <thead><tr><th>Line item</th><th className="r">Allocated</th><th className="r">Spent</th><th className="r">Remaining</th><th>Utilization</th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.name}>
                <td>{r.name}</td>
                <td className="r num">{peso(r.allocated)}</td>
                <td className="r num">{peso(r.spent)}</td>
                <td className="r num mono-b">{peso(r.allocated - r.spent)}</td>
                <td><UtilBar pct={r.allocated > 0 ? (r.spent / r.allocated) * 100 : 0} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
