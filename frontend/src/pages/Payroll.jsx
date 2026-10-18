import { useEffect, useState } from 'react';
import Chip, { statusTone } from '../components/Chip';
import { peso } from '../components/money';
import { getWithFallback, mutateWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { payrollRun, CURRENT_PROJECT } from '../data/mockData';
import { useAuth } from '../context/AuthContext';

export default function Payroll() {
  const { user } = useAuth();
  const [run, setRun] = useState(null);
  const [computing, setComputing] = useState(false);

  useEffect(() => {
    getWithFallback(ENDPOINTS.payroll(CURRENT_PROJECT.id), payrollRun).then(setRun);
  }, []);

  async function compute() {
    setComputing(true);
    try {
      const fresh = await mutateWithFallback('post', ENDPOINTS.computePayroll(CURRENT_PROJECT.id), { month: run.month }, payrollRun);
      setRun(fresh);
    } finally {
      setComputing(false);
    }
  }

  if (!run) return <div className="center-fill">Loading payroll…</div>;

  const totalGross = run.rows.reduce((t, r) => t + r.gross, 0);
  const totalDeductions = run.deductionBreakdown.reduce((t, d) => t + d.amount, 0);

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Payroll</h2>
          <p>SAGIP-Lawa · Monthly PS computation and release tracking</p>
        </div>
        <div className="actions">
          <button className="btn">Payroll register (PDF)</button>
          {user.role === 'RA' && (
            <button className="btn primary" onClick={compute} disabled={computing}>
              {computing && <span className="spin" />}Compute {run.month}
            </button>
          )}
        </div>
      </div>

      <div className="filter-row">
        <select defaultValue={run.month}><option>{run.month}</option></select>
        <select><option>All personnel</option><option>Project Leader</option><option>Research Aides</option><option>Consultants</option></select>
      </div>

      <div className="panel wide-scroll" style={{ marginBottom: 14 }}>
        <table>
          <thead><tr><th>Personnel</th><th>Line item</th><th className="r">Basic rate</th><th className="r">Gross pay</th><th className="r">Deductions</th><th className="r">Net pay</th><th>Status</th></tr></thead>
          <tbody>
            {run.rows.map((r) => (
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

      <div className="grid c2">
        <div className="panel">
          <div className="panel-head"><h3>Deductions · {run.rows[1]?.name} · {run.month}</h3></div>
          <div className="wide-scroll">
            <table>
              <thead><tr><th>Type</th><th className="r">Amount</th></tr></thead>
              <tbody>
                {run.deductionBreakdown.map((d) => (
                  <tr key={d.type}><td>{d.type}</td><td className="r num">{peso(d.amount)}</td></tr>
                ))}
                <tr style={{ background: 'color-mix(in srgb, var(--gold) 8%, transparent)' }}>
                  <td className="mono-b">Total deductions</td><td className="r num mono-b">{peso(totalDeductions)}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div className="panel">
          <div className="panel-head"><h3>Approval flow</h3><span className="hint">{run.month} run</span></div>
          <div>
            <div className="alert-line"><span className="alert-dot" style={{ background: 'var(--ok)' }} />
              <div><b>Computed by N. Haron (Research Assistant)</b><small>{run.rows.length} personnel · gross {peso(totalGross)}</small></div>
            </div>
            <div className="alert-line"><span className="alert-dot" style={{ background: 'var(--warn)' }} />
              <div><b>Awaiting Project Leader approval</b><small>Notified on submission</small></div>
            </div>
            <div className="alert-line"><span className="alert-dot" style={{ background: 'var(--line-strong)' }} />
              <div><b>Release &amp; charge to LIB</b><small>Net pay is charged to PS line items only after approval.</small></div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
