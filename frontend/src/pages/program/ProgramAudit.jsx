import { useCallback, useEffect, useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { getWithFallback } from '../../api/http';
import { auditLog as mockAuditLog } from '../../data/mockData';

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

export default function ProgramAudit() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [flaggedOnly, setFlaggedOnly] = useState(false);
  const [action, setAction] = useState('');
  const [auditMock] = useState(mockAuditLog);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getWithFallback(
        ENDPOINTS.audit,
        (params) => getWithFallback(ENDPOINTS.audit, null, { params })
      );
      // getWithFallback only accepts (url, fallback, config); call client directly instead.
      const client = (await import('../../api/client')).default;
      const response = await client.get(ENDPOINTS.audit, {
        params: {
          from: from || undefined,
          to: to || undefined,
          flagged: flaggedOnly ? 1 : undefined,
          action: action || undefined,
        },
      });
      setRows(Array.isArray(response.data) ? response.data : []);
    } catch (requestError) {
      setError(messageFor(requestError, 'Unable to load the audit trail.'));
      if (false) setRows([]);
    } finally {
      setLoading(false);
    }
  }, [from, to, flaggedOnly, action]);

  useEffect(() => {
    load();
  }, [load]);

  const filtered = rows.filter((row) => {
    if (action && row.action !== action) return false;
    if (flaggedOnly && !['rejected', 'budget_changed'].includes(row.action)) return false;
    if (from && row.date < from) return false;
    if (to && row.date > to) return false;
    return true;
  });

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Audit trail · Program Leader</div>
          <h1 className="program-h1">Audit trail</h1>
          <p className="program-sub">
            Log requests, approvals, rejections, registrations and budget changes.
          </p>
        </div>
      </div>

      <div className="program-filter-row">
        <div className="program-field">
          <label htmlFor="audit-action">Action</label>
          <select id="audit-action" className="program-select" value={action} onChange={(e) => setAction(e.target.value)}>
            <option value="">All actions</option>
            <option value="approved">approved</option>
            <option value="rejected">rejected</option>
            <option value="created">created</option>
            <option value="budget_changed">budget_changed</option>
          </select>
        </div>
        <div className="program-field">
          <label htmlFor="audit-from">From</label>
          <input id="audit-from" className="program-input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div className="program-field">
          <label htmlFor="audit-to">To</label>
          <input id="audit-to" className="program-input" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
        <button
          type="button"
          className={`program-btn${flaggedOnly ? ' primary' : ''}`}
          aria-pressed={flaggedOnly}
          onClick={() => setFlaggedOnly((value) => !value)}
        >
          Flagged only
        </button>
        <span className="spacer" />
        <button type="button" className="program-btn" onClick={load}>Refresh</button>
      </div>

      <section className="program-card">
        {loading ? (
          <div className="program-empty">Loading audit entries…</div>
        ) : (
          <div className="program-scroll">
            <table className="program-table" style={{ minWidth: 960 }}>
              <thead>
                <tr>
                  <th>Timestamp</th>
                  <th>User</th>
                  <th>Action</th>
                  <th>Record</th>
                  <th>Project</th>
                  <th>Change</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((row) => (
                  <tr key={row.id}>
                    <td className="num">{row.ts}</td>
                    <td>{row.user}</td>
                    <td><span className={`program-pill ${row.action === 'rejected' ? 'risk' : row.action === 'approved' || row.action === 'created' ? 'healthy' : 'neutral'}`}>{row.action}</span></td>
                    <td>{row.record}</td>
                    <td>{row.project || '—'}</td>
                    <td>{row.change}</td>
                  </tr>
                ))}
                {filtered.length === 0 && <tr><td className="program-empty">No audit entries match these filters.</td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
