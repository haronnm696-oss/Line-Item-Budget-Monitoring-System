import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import Chip, { statusTone } from '../components/Chip';
import UtilBar from '../components/UtilBar';
import { peso } from '../components/money';
import { getWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { CURRENT_PROJECT, libCategoriesPS, libCategoriesMOOE } from '../data/mockData';

function sum(rows, key) { return rows.reduce((t, r) => t + r[key], 0); }

export default function ProjectDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);

  useEffect(() => {
    getWithFallback(ENDPOINTS.project(id), {
      project: CURRENT_PROJECT,
      ps: libCategoriesPS,
      mooe: libCategoriesMOOE,
    }).then(setData);
  }, [id]);

  if (!data) return <div className="center-fill">Loading project…</div>;

  const { project, ps, mooe } = data;
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
        <div className="actions">
          <button className="btn">Edit project</button>
          <button className="btn primary">Add line item</button>
        </div>
      </div>

      <div className="tabs">
        <button className="active">LIB line items</button>
        <button onClick={() => navigate('/expenses')}>Expenses</button>
        <button onClick={() => navigate('/payroll')}>Payroll</button>
        <button onClick={() => navigate('/users')}>Team</button>
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
                <td className="r num">{((psSpent / psAllocated) * 100).toFixed(1)}%</td>
              </tr>
              <tr>
                <td className="mono-b">MOOE</td>
                <td className="r num">{peso(mooeAllocated)}</td>
                <td className="r num">{peso(mooeSpent)}</td>
                <td className="r num">{peso(mooeAllocated - mooeSpent)}</td>
                <td className="r num">{((mooeSpent / mooeAllocated) * 100).toFixed(1)}%</td>
              </tr>
              <tr style={{ background: 'color-mix(in srgb, var(--gold) 8%, transparent)' }}>
                <td className="mono-b">Total</td>
                <td className="r num mono-b">{peso(totalAllocated)}</td>
                <td className="r num mono-b">{peso(totalSpent)}</td>
                <td className="r num mono-b">{peso(totalAllocated - totalSpent)}</td>
                <td className="r num mono-b">{((totalSpent / totalAllocated) * 100).toFixed(1)}%</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
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
                <td><UtilBar pct={(r.spent / r.allocated) * 100} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
