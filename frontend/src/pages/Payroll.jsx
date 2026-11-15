import { useState } from 'react';
import Chip, { statusTone } from '../components/Chip';
import LoadState from '../components/LoadState';
import ProjectPicker from '../components/ProjectPicker';
import { peso } from '../components/money';
import { apiWrite } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { useAuth } from '../context/AuthContext';
import { SAVE_ERROR } from '../lib/apiResult';
import { useApiData } from '../lib/useApiData';
import { useAssignedProject } from '../lib/useAssignedProject';

export default function Payroll() {
  const { user } = useAuth();
  const picker = useAssignedProject();
  // GET /api/projects/:id/payroll (not built yet): a failure shows an error
  // with "Try again"; there is no sample payroll.
  const payroll = useApiData(picker.projectId ? ENDPOINTS.payroll(picker.projectId) : null);
  const [computing, setComputing] = useState(false);
  const [notice, setNotice] = useState(null);

  const run = payroll.data;
  const rows = Array.isArray(run?.rows) ? run.rows : [];
  const breakdown = Array.isArray(run?.deductionBreakdown) ? run.deductionBreakdown : [];

  async function compute() {
    setComputing(true);
    setNotice(null);
    try {
      await apiWrite('post', ENDPOINTS.computePayroll(picker.projectId), { month: run?.month });
      setNotice({ tone: 'ok', text: 'Payroll computed.' });
      payroll.retry();
    } catch (error) {
      setNotice({ tone: 'over', text: error.message || SAVE_ERROR });
    } finally {
      setComputing(false);
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Payroll</h2>
          <p>{picker.project ? `${picker.project.title} · ` : ''}Monthly PS computation and release tracking</p>
        </div>
        <ProjectPicker picker={picker} id="payroll-project" />
      </div>

      <LoadState state={picker.state} loadingText="Loading your projects…" isEmpty={picker.projects.length === 0} emptyText="You are not assigned to any project yet.">
        <LoadState state={payroll} loadingText="Loading payroll…" isEmpty={rows.length === 0} emptyText="No payroll has been computed for this project yet.">
          {notice && <div className={`banner ${notice.tone}`} role={notice.tone === 'over' ? 'alert' : 'status'}>{notice.text}</div>}
          {user.role === 'RA' && (
            <div className="actions" style={{ marginBottom: 12 }}>
              <button className="btn primary" onClick={compute} disabled={computing}>
                {computing && <span className="spin" />}Compute {run?.month ?? ''}
              </button>
            </div>
          )}

          <div className="panel wide-scroll" style={{ marginBottom: 14 }}>
            <table>
              <thead><tr><th>Personnel</th><th>Line item</th><th className="r">Basic rate</th><th className="r">Gross pay</th><th className="r">Deductions</th><th className="r">Net pay</th><th>Status</th></tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td><b>{r.name}</b><br /><small style={{ color: 'var(--muted)' }}>{r.position}</small></td>
                    <td>{r.category}</td>
                    <td className="r num">{peso(r.basic)}</td>
                    <td className="r num">{peso(r.gross)}</td>
                    <td className="r num">{peso(r.deductions)}</td>
                    <td className="r num mono-b">{peso(r.net)}</td>
                    <td><Chip tone={statusTone(r.status)}>{r.status}</Chip></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {breakdown.length > 0 && (
            <div className="panel">
              <div className="panel-head"><h3>Deductions · {run?.month}</h3></div>
              <div className="wide-scroll">
                <table>
                  <thead><tr><th>Type</th><th className="r">Amount</th></tr></thead>
                  <tbody>
                    {breakdown.map((d) => (
                      <tr key={d.type}><td>{d.type}</td><td className="r num">{peso(d.amount)}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </LoadState>
      </LoadState>
    </>
  );
}
