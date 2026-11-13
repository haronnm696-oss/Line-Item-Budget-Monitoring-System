import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { fetchProgramProjects } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import { formatPct } from '../../components/money';

const FILTER_KEYS = ['q', 'status', 'funder', 'risk'];

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

function csvCell(value) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function downloadCSV(content, filename) {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.setAttribute('href', url);
  anchor.setAttribute('download', filename);
  anchor.style.visibility = 'hidden';
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

const SORTERS = {
  title: (project) => (project.title ?? '').toLowerCase(),
  spent: (project) => Number(project.spent_pct) || 0,
  period: (project) => project.start_date ?? '',
};

export default function ProgramProjects() {
  const navigate = useNavigate();
  const location = useLocation();
  const [params, setParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [sort, setSort] = useState({ key: null, dir: 'asc' });

  const search = params.get('q') ?? '';
  const status = params.get('status') ?? '';
  const funder = params.get('funder') ?? '';
  const atRiskOnly = params.get('risk') === '1';
  const hasFilters = FILTER_KEYS.some((key) => params.get(key));

  // The funder filter runs on the server, exactly like the overview.
  useEffect(() => {
    let alive = true;
    setLoading(true);
    setError('');
    fetchProgramProjects(funder)
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
  }, [funder]);

  function setFilter(key, value) {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  }

  function clearFilters() {
    const next = new URLSearchParams(params);
    FILTER_KEYS.forEach((key) => next.delete(key));
    setParams(next, { replace: true });
  }

  function toggleSort(key) {
    setSort((current) => (current.key === key
      ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' }
      : { key, dir: 'asc' }));
  }

  const projects = useMemo(() => (Array.isArray(data?.projects) ? data.projects : []), [data]);
  const funders = useMemo(() => (Array.isArray(data?.funders) ? data.funders : []), [data]);
  const atRiskCount = projects.filter((project) => project.health === 'At risk').length;

  const rows = useMemo(() => {
    const query = search.trim().toLowerCase();
    const filtered = projects.filter((project) => {
      const matchesSearch = !query
        || project.title?.toLowerCase().includes(query)
        || project.code?.toLowerCase().includes(query);
      const matchesStatus = !status || project.status === status;
      const matchesRisk = !atRiskOnly || project.health === 'At risk';
      return matchesSearch && matchesStatus && matchesRisk;
    });

    if (!sort.key) return filtered;
    const value = SORTERS[sort.key];
    const direction = sort.dir === 'asc' ? 1 : -1;
    return [...filtered].sort((a, b) => {
      const left = value(a);
      const right = value(b);
      if (left < right) return -1 * direction;
      if (left > right) return 1 * direction;
      return 0;
    });
  }, [projects, search, status, atRiskOnly, sort]);

  function ariaSort(key) {
    if (sort.key !== key) return 'none';
    return sort.dir === 'asc' ? 'ascending' : 'descending';
  }

  function sortHeader(key, label) {
    const active = sort.key === key;
    return (
      <th aria-sort={ariaSort(key)}>
        <button type="button" className="pp-sort" onClick={() => toggleSort(key)}>
          {label}
          <span className={`pp-sort-icon${active ? ' is-active' : ''}`} aria-hidden="true">
            {active ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}
          </span>
        </button>
      </th>
    );
  }

  function exportCsv() {
    const header = ['Project', 'Code', 'Funder', 'Reference', 'Project Leader', 'Budget', 'Spent %', 'Period', 'Status', 'Health'];
    const lines = rows.map((project) => [
      project.title,
      project.code,
      project.funder,
      project.funder_reference,
      project.project_leader,
      Number(project.budget || 0).toFixed(2),
      formatPct(project.spent_pct),
      projectPeriod(project.start_date, project.end_date),
      project.status,
      project.health,
    ].map(csvCell).join(','));
    downloadCSV([header.map(csvCell).join(','), ...lines].join('\n'), 'program-projects.csv');
  }

  const detailState = { from: `${location.pathname}${location.search}` };

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Projects · Program Leader</div>
          <h1 className="program-h1">Projects</h1>
          <p className="program-sub">Externally funded research projects across every funder.</p>
        </div>
        <div className="program-head-actions">
          <button type="button" className="program-btn" onClick={exportCsv} disabled={loading || rows.length === 0}>
            Export CSV
          </button>
          <button type="button" className="program-btn pp-soon" disabled title="Available soon">
            New project
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
            onChange={(event) => setFilter('q', event.target.value)}
            placeholder="Search title or code"
          />
        </div>

        <div className="program-field">
          <label htmlFor="project-status">Status</label>
          <select
            id="project-status"
            className="program-select"
            value={status}
            onChange={(event) => setFilter('status', event.target.value)}
          >
            <option value="">All statuses</option>
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
            onChange={(event) => setFilter('funder', event.target.value)}
          >
            <option value="">All funders</option>
            {funders.map((agency) => (
              <option key={agency.id} value={agency.id}>{agency.label}</option>
            ))}
          </select>
        </div>

        <button
          type="button"
          className={`program-btn pp-toggle${atRiskOnly ? ' is-active' : ''}`}
          aria-pressed={atRiskOnly}
          onClick={() => setFilter('risk', atRiskOnly ? '' : '1')}
        >
          At risk · {atRiskCount}
        </button>

        {hasFilters && (
          <button type="button" className="pp-clear" onClick={clearFilters}>
            Clear filters
          </button>
        )}
      </div>

      <section className="program-card">
        {loading ? (
          <div className="program-empty">Loading projects…</div>
        ) : rows.length === 0 ? (
          <div className="program-empty pp-empty">
            No projects match these filters.{' '}
            {hasFilters && (
              <button type="button" className="pp-clear" onClick={clearFilters}>Clear filters</button>
            )}
          </div>
        ) : (
          <div className="program-scroll">
            <table className="program-table pp-table">
              <thead>
                <tr>
                  {sortHeader('title', 'Project')}
                  <th>Funder</th>
                  <th>Project Leader</th>
                  {sortHeader('spent', 'Spent')}
                  {sortHeader('period', 'Period')}
                  <th>Status</th>
                  <th>Health</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((project) => (
                  <tr
                    key={project.id}
                    className="pp-row"
                    onClick={(event) => {
                      // The title link handles its own click (and keyboard).
                      if (event.target.closest('a')) return;
                      navigate(`/program-projects/${project.id}`, { state: detailState });
                    }}
                  >
                    <td>
                      <Link
                        className="project-name pp-link"
                        to={`/program-projects/${project.id}`}
                        state={detailState}
                      >
                        {project.title}
                      </Link>
                      <div className="project-code">
                        {project.code} · ref. {project.funder_reference || '—'}
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
                    <td>
                      <ProgramPill value={project.health} />
                      <div className="pl-health-reason">{project.health_reason}</div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
