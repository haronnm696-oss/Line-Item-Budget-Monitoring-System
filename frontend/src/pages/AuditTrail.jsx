import { useEffect, useState } from 'react';
import Chip from '../components/Chip';
import { getWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { auditLog } from '../data/mockData';

const ACTION_TONE = { created: 'neutral', updated: 'neutral', verified: 'ok', approved: 'ok', rejected: 'over' };

export default function AuditTrail() {
  const [log, setLog] = useState([]);
  const [search, setSearch] = useState('');
  const [action, setAction] = useState('All');

  useEffect(() => {
    getWithFallback(ENDPOINTS.audit, auditLog).then(setLog);
  }, []);

  const actions = ['All', ...new Set(auditLog.map((a) => a.action))];
  const filtered = log.filter((a) =>
    (action === 'All' || a.action === action) &&
    (a.user.toLowerCase().includes(search.toLowerCase()) || a.record.toLowerCase().includes(search.toLowerCase()))
  );

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Audit trail</h2>
          <p>Immutable log of every financial action. Entries cannot be edited or deleted.</p>
        </div>
        <div className="actions"><button className="btn">Export log</button></div>
      </div>

      <div className="filter-row">
        <input type="search" placeholder="Search user or record" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select value={action} onChange={(e) => setAction(e.target.value)}>
          {actions.map((a) => <option key={a} value={a}>{a === 'All' ? 'All actions' : a[0].toUpperCase() + a.slice(1)}</option>)}
        </select>
        <select><option>All record types</option><option>Expense</option><option>Payroll</option><option>Project</option><option>LibCategory</option></select>
        <input type="date" />
      </div>

      <div className="panel wide-scroll">
        <table>
          <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Record</th><th>Change</th><th>IP address</th></tr></thead>
          <tbody>
            {filtered.map((a) => (
              <tr key={a.id}>
                <td className="num">{a.ts}</td>
                <td>{a.user}</td>
                <td><Chip tone={ACTION_TONE[a.action] || 'neutral'}>{a.action}</Chip></td>
                <td>{a.record}</td>
                <td>{a.change}</td>
                <td className="num">{a.ip}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
