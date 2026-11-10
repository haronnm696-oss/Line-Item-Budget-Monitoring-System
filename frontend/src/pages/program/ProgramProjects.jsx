import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { fetchProgramOverview } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import { formatPct } from '../../components/money';

function projectPeriod(start, end) {
  if (!start || !end) return '—';
  const from = new Date(start);
  const to = new Date(end);
  if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return '—';
  const sameYear = from.getFullYear() === to.getFullYear();
  const fromLabel = from.toLocaleDateString('en-PH', sameYear
    ? { month: 'short' }
    : { month: 'short', year: 'numeric' });
  const toLabel = to.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
  return `${fromLabel} – ${toLabel}`;
}

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

export default function ProgramProjects() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [funder, setFunder] = useState('all');
  const [atRiskOnly, setAtRiskOnly] = useState(false);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    fetchProgramOverview()
      .then((payload) => {
        if (alive) setData(payload);
      })
      .catch((requestError) => {
        if (alive) setError(requestError?.response?.data?.message || 'Unable to load projects.');
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => { alive = false; };
  }, []);

  const projects = useMemo(() => (Array.isArray(data?.projects) ? data.projects : []), [data]);
  const funders = useMemo(() => (Array.isArray(data?.funders) ? data.funders : []), [data]);

  const atRiskCount = projects.filter((project) => project.health === 'At risk').length;

  const filtered = projects.filter((project) => {
    const query = search.trim().toLowerCase();
    const matchesSearch = !query
      || project.title?.toLowerCase().includes(query)
      || project.code?.toLowerCase().includes(query);
    const matchesStatus = status === 'all' || project.status === status;
    const matchesFunder = funder === 'all'
      || String(project.funder_id) === String(funder)
      || project.funder === funder;
    const matchesRisk = !atRiskOnly || project.health === 'At risk';
    return matchesSearch && matchesStatus && matchesFunder && matchesRisk;
  });

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Projects · Program Leader</div>
          <h1 className="program-h1">Projects</h1>
          <p className="program-sub">Externally funded research projects across every funder.</p>
        </div>
        <div className="program-head-actions">
          <button
            type="button"
            className="program-btn primary"
            disabled
            title="The project creation API is not implemented yet."
          >
            New project · Coming soon
          </button>
        </div>
      </div>

      {error && (
        <div className="program-banner error" role="alert">{error}</div>
      )}

      <div className="program-filter-row">
        <div className="program-field">
          <label htmlFor="project-search">Title or code</label>
          <input
            id="project-search"
            className="program-input"
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search title or code"
          />
        </div>

        <div className="program-field">
          <label htmlFor="project-status">Status</label>
          <select
            id="project-status"
            className="program-select"
            value={status}
            onChange={(event) => setStatus(event.target.value)}
          >
            <option value="all">All statuses</option>
            <option value="Ongoing">Ongoing</option>
            <option value="Completed">Completed</option>
            <option value="Planning">Planning</option>
            <option value="Terminated">Terminated</option>
          </select>
        </div>

        <div className="program-field">
          <label htmlFor="project-funder">Funder</label>
          <select
            id="project-funder"
            className="program-select"
            value={funder}
            onChange={(event) => setFunder(event.target.value)}
          >
            <option value="all">All funders</option>
            {funders.map((agency) => (
              <option key={agency.id} value={agency.id}>{agency.label}</option>
            ))}
          </select>
        </div>

        <button
          type="button"
          className="program-btn"
          aria-pressed={atRiskOnly}
          onClick={() => setAtRiskOnly((value) => !value)}
        >
          At risk · {atRiskCount}
        </button>
      </div>

      <section className="program-card">
        {loading ? (
          <div className="program-empty">Loading projects…</div>
        ) : (
          <div className="program-scroll">
            <table className="program-table" style={{ minWidth: 900 }}>
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Funder</th>
                  <th>Project Leader</th>
                  <th>Spent</th>
                  <th>Period</th>
                  <th>Status</th>
                  <th>Health</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((project) => (
                  <tr
                    key={project.id}
                    onClick={() => navigate(`/program-projects/${project.id}`)}
                    style={{ cursor: 'pointer' }}
                  >
                    <td>
                      <div className="project-name">{project.title}</div>
                      <div className="project-code">
                        {project.code} · ref. {project.funding_reference || '—'}
                      </div>
                    </td>
                    <td>{project.funder}</td>
                    <td>{project.project_leader || '—'}</td>
                    <td>
                      <div className="num">{formatPct(project.spent_pct)}</div>
                      <div className="program-progress" style={{ marginTop: 6 }}>
                        <span style={{ width: `${clampPct(project.spent_pct)}%` }} />
                      </div>
                    </td>
                    <td>{projectPeriod(project.start_date, project.end_date)}</td>
                    <td><ProgramPill value={project.status} /></td>
                    <td><ProgramPill value={project.health} /></td>
                  </tr>
                ))}
                {filtered.length === 0 && (
                  <tr>
                    <td colSpan={7} className="program-empty">No projects match these filters.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}

        <p className="program-help" style={{ marginTop: 14 }}>
          New project is not available yet. Missing: a project create/update API, validation,
          funding-agency and Project Leader assignment, tranche setup, and an audit entry.
        </p>
      </section>
    </>
  );
}
