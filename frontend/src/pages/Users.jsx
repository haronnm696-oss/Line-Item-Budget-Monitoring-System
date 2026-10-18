import { useEffect, useState } from 'react';
import Chip, { statusTone } from '../components/Chip';
import { getWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { team as mockTeam, CURRENT_PROJECT } from '../data/mockData';

const CAPABILITIES = [
  ['View dashboards & reports', '✓ all projects', '✓ own projects', '✓ assigned', '✓ assigned', '✓ assigned'],
  ['Record expenses', '—', '—', '✓', '—', '—'],
  ['Compute payroll', '—', '—', '✓', '—', '—'],
  ['Verify / reject expenses', '—', '—', '—', '—', '✓'],
  ['Approve payroll', '—', '✓', '—', '—', '—'],
  ['Manage projects & line items', '✓', '✓ own', '—', '—', '—'],
  ['View audit trail', '✓', '✓', '—', '—', '✓'],
];

export default function Users() {
  const [team, setTeam] = useState([]);

  useEffect(() => {
    getWithFallback(ENDPOINTS.team(CURRENT_PROJECT.id), mockTeam).then(setTeam);
  }, []);

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Users &amp; roles</h2>
          <p>Roles are assigned per project — a person can hold different roles on different projects.</p>
        </div>
        <div className="actions">
          <button className="btn">Add user</button>
          <button className="btn primary">Assign to project</button>
        </div>
      </div>

      <div className="tabs">
        <button className="active">SAGIP-Lawa team</button>
        <button>All system users</button>
        <button>Role definitions</button>
      </div>

      <div className="panel wide-scroll">
        <table>
          <thead><tr><th>Name</th><th>Employee ID</th><th>Position</th><th>Role on this project</th><th>Assigned</th><th>Status</th></tr></thead>
          <tbody>
            {team.map((m) => (
              <tr key={m.email}>
                <td><b>{m.name}</b><br /><small style={{ color: 'var(--muted)' }}>{m.email}</small></td>
                <td className="num">{m.empId}</td>
                <td>{m.position}</td>
                <td><span className="chip brand">{m.role}</span></td>
                <td className="num">{m.assigned}</td>
                <td><Chip tone={statusTone(m.status)}>{m.status}</Chip></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="panel" style={{ marginTop: 14 }}>
        <div className="panel-head"><h3>What each role can do</h3></div>
        <div className="wide-scroll">
          <table>
            <thead><tr><th>Capability</th><th>Program Leader</th><th>Project Leader</th><th>Research Assistant</th><th>Researcher</th><th>Finance/Admin</th></tr></thead>
            <tbody>
              {CAPABILITIES.map((row) => (
                <tr key={row[0]}>{row.map((cell, i) => <td key={i}>{cell}</td>)}</tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
