import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { downloadProgramProjectsExcel, fetchProgramProjects } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import { compactPeso, formatPct, peso } from '../../components/money';

const FILTER_KEYS = ['q', 'status', 'funder', 'leader', 'year', 'risk'];
const DASH = '—';

function projectPeriod(start, end) {
  if (!start || !end) return DASH;
  const from = new Date(start);
  const to = new Date(end);
  if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return DASH;
  const sameYear = from.getFullYear() === to.getFullYear();
  const fromLabel = from.toLocaleDateString('en-PH', sameYear
    ? { month: 'short' }
    : { month: 'short', year: 'numeric' });
  const toLabel = to.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
  return `${fromLabel} – ${toLabel}`;
}

/** "today", "3 days ago", "2 months ago" for a YYYY-MM-DD date. */
function relativeDate(value) {
  if (!value) return null;
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const days = Math.round((today - date) / 86_400_000);
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
  if (Math.abs(days) < 30) return rtf.format(-days, 'day');
  if (Math.abs(days) < 365) return rtf.format(-Math.round(days / 30), 'month');
  return rtf.format(-Math.round(days / 365), 'year');
}

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

function pctOrDash(value) {
  return value == null ? DASH : formatPct(value);
}

function moneyOrDash(value, format = peso) {
  return value == null ? DASH : format(value);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.style.visibility = 'hidden';
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

function SplitBar({ label, bucket, tone }) {
  const pct = bucket?.utilization_pct;
  return (
    <div className="pp-split">
      <span className="pp-split-label">{label}</span>
      <span className={`pp-split-bar ${tone}`} aria-hidden="true">
        <span style={{ width: `${clampPct(pct)}%` }} />
      </span>
      <span className="pp-split-pct">{pctOrDash(pct)}</span>
    </div>
  );
}

function RowActions({ project, detailState }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState(null);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);

  function openMenu() {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect) {
      // Fixed position so the table's horizontal scroll never clips the menu.
      const openUp = rect.bottom + 220 > window.innerHeight;
      setPosition({
        right: Math.max(8, window.innerWidth - rect.right),
        ...(openUp
          ? { bottom: window.innerHeight - rect.top + 4 }
          : { top: rect.bottom + 4 }),
      });
    }
    setOpen(true);
  }

  useEffect(() => {
    if (!open) return undefined;

    menuRef.current?.querySelector('[role="menuitem"]:not([aria-disabled="true"])')?.focus();

    const close = () => setOpen(false);
    const onPointerDown = (event) => {
      if (menuRef.current?.contains(event.target) || buttonRef.current?.contains(event.target)) return;
      setOpen(false);
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setOpen(false);
        buttonRef.current?.focus();
        return;
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const items = [...(menuRef.current?.querySelectorAll('[role="menuitem"]') ?? [])];
        if (items.length === 0) return;
        event.preventDefault();
        const index = items.indexOf(document.activeElement);
        const step = event.key === 'ArrowDown' ? 1 : -1;
        items[(index + step + items.length) % items.length].focus();
      }
    };

    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('resize', close);
      window.removeEventListener('scroll', close, true);
    };
  }, [open]);

  const close = () => setOpen(false);
  const items = [
    { label: 'View details', to: `/program-projects/${project.id}`, state: detailState },
    { label: 'Export Form B', to: `/program-reports?project=${encodeURIComponent(project.code ?? '')}` },
    { label: 'View approvals', to: `/program-approvals?project=${project.id}` },
    { label: 'View audit trail', to: `/program-audit?project=${project.id}` },
  ];

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        className="pp-more"
        aria-label={`Actions for ${project.title}`}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => (open ? setOpen(false) : openMenu())}
      >
        <span aria-hidden="true">⋯</span>
      </button>
      {open && (
        <div
          ref={menuRef}
          className="pp-menu"
          role="menu"
          aria-label={`Actions for ${project.title}`}
          style={position ?? undefined}
        >
          {items.map((item) => (
            <Link
              key={item.label}
              role="menuitem"
              className="pp-menu-item"
              to={item.to}
              state={item.state}
              onClick={close}
            >
              {item.label}
            </Link>
          ))}
        </div>
      )}
    </>
  );
}

export default function ProgramProjects() {
  const navigate = useNavigate();
  const location = useLocation();
  const [params, setParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exporting, setExporting] = useState(false);

  const search = params.get('q') ?? '';
  const status = params.get('status') ?? '';
  const funder = params.get('funder') ?? '';
  const leader = params.get('leader') ?? '';
  const year = params.get('year') ?? '';
  const atRiskOnly = params.get('risk') === '1';
  const sortKey = params.get('sort') ?? '';
  const sortDir = params.get('dir') === 'desc' ? 'desc' : 'asc';
  const hasFilters = FILTER_KEYS.some((key) => params.get(key));

  // Typing updates the URL immediately; the request waits for a short pause.
  const [debouncedSearch, setDebouncedSearch] = useState(search);
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 250);
    return () => clearTimeout(timer);
  }, [search]);

  const query = {
    q: debouncedSearch.trim(),
    status,
    funder,
    leader,
    year,
    risk: atRiskOnly ? 1 : '',
    sort: sortKey,
    dir: sortKey ? sortDir : '',
  };
  const queryKey = JSON.stringify(query);

  // Every filter, sort and total is applied by the server.
  useEffect(() => {
    let alive = true;
    setLoading(true);
    setError('');
    fetchProgramProjects(JSON.parse(queryKey))
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
  }, [queryKey]);

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
    const next = new URLSearchParams(params);
    const dir = sortKey === key && sortDir === 'asc' ? 'desc' : 'asc';
    next.set('sort', key);
    next.set('dir', dir);
    setParams(next, { replace: true });
  }

  const rows = Array.isArray(data?.projects) ? data.projects : [];
  const funders = Array.isArray(data?.funders) ? data.funders : [];
  const leaders = Array.isArray(data?.leaders) ? data.leaders : [];
  const years = Array.isArray(data?.years) ? data.years : [];
  const summary = data?.summary ?? null;
  const atRiskCount = data?.at_risk_count ?? 0;

  function ariaSort(key) {
    if (sortKey !== key) return 'none';
    return sortDir === 'asc' ? 'ascending' : 'descending';
  }

  function sortHeader(key, label, className = '') {
    const active = sortKey === key;
    return (
      <th aria-sort={ariaSort(key)} className={className || undefined}>
        <button type="button" className="pp-sort" onClick={() => toggleSort(key)}>
          {label}
          <span className={`pp-sort-icon${active ? ' is-active' : ''}`} aria-hidden="true">
            {active ? (sortDir === 'asc' ? '▲' : '▼') : '↕'}
          </span>
        </button>
      </th>
    );
  }

  async function exportExcel() {
    setExporting(true);
    setError('');
    try {
      const { blob, filename } = await downloadProgramProjectsExcel(query);
      saveBlob(blob, filename);
    } catch {
      setError('Unable to export the Excel file. Try again.');
    } finally {
      setExporting(false);
    }
  }

  function exportPdf() {
    if (!summary) return;
    const filterLabel = summary.funder_name || 'All funders';
    const cell = 'padding:6px 8px;border-bottom:1px solid #e5e0d8;vertical-align:top';
    const right = `${cell};text-align:right`;
    const head = 'text-align:left;padding:6px 8px;border-bottom:2px solid #d9d2c7';

    const projectRows = rows.map((project) => {
      const closed = project.health === 'Closed';
      return `<tr>
        <td style="${cell}"><b>${escapeHtml(project.title || DASH)}</b><br>
          <span style="color:#6b625b">${escapeHtml(project.code || DASH)} · Ref. ${escapeHtml(project.funder_reference || DASH)}</span><br>
          <span style="color:#6b625b">${escapeHtml(project.funder || DASH)} · ${escapeHtml(project.project_leader || DASH)}</span></td>
        <td style="${right}">${escapeHtml(moneyOrDash(project.budget))}</td>
        <td style="${right}">${escapeHtml(moneyOrDash(project.spent))}<br><span style="color:#6b625b">${escapeHtml(pctOrDash(project.spent_pct))}</span></td>
        <td style="${right}">PS ${escapeHtml(pctOrDash(project.ps?.utilization_pct))}<br>MOOE ${escapeHtml(pctOrDash(project.mooe?.utilization_pct))}</td>
        <td style="${right}">${escapeHtml(moneyOrDash(project.remaining))}</td>
        <td style="${cell}">${escapeHtml(projectPeriod(project.start_date, project.end_date))}<br>
          <span style="color:#6b625b">Last entry: ${escapeHtml(project.last_entry_date || 'none yet')}</span></td>
        <td style="${cell}">${escapeHtml(project.status || DASH)}</td>
        <td style="${cell}">${escapeHtml(closed ? DASH : project.health || DASH)}<br>
          <span style="color:#6b625b">${escapeHtml(project.health_note || '')}</span></td>
        <td style="${right}">${project.pending_approvals > 0 ? `${project.pending_approvals} pending` : 'No pending'}</td>
      </tr>`;
    }).join('');

    const totals = [
      ['Projects', `${summary.projects} (${summary.ongoing} ongoing · ${summary.completed} completed)`],
      ['Total allocated', peso(summary.allocated)],
      ['Total spent', `${peso(summary.spent)} (${formatPct(summary.spent_pct)} of allocated)`],
      ['Remaining', `${peso(summary.remaining)} (${formatPct(summary.remaining_pct)} still available)`],
    ].map(([label, value]) => `<tr><td style="${cell}">${escapeHtml(label)}</td><td style="${right}">${escapeHtml(value)}</td></tr>`).join('');

    const html = `<!doctype html><html><head><meta charset="utf-8"><title>Program projects</title>
      <style>@page{size:A4 landscape;margin:12mm}</style></head>
      <body style="font-family:Inter,system-ui,-apple-system,sans-serif;color:#1f1a17;padding:24px;font-size:11px">
        <h1 style="margin:0 0 4px;color:#6F1D2A;font-size:20px">Program projects</h1>
        <p style="margin:0 0 16px;color:#6b625b">${escapeHtml(filterLabel)} · Generated ${escapeHtml(new Date().toLocaleString('en-PH'))} · LIB Monitoring System, MSU–Main Campus</p>
        <table style="border-collapse:collapse;width:100%;max-width:520px;margin-bottom:18px">${totals}</table>
        <table style="border-collapse:collapse;width:100%">
          <thead><tr>
            <th style="${head}">Project</th>
            <th style="${head};text-align:right">Budget</th>
            <th style="${head};text-align:right">Spent</th>
            <th style="${head};text-align:right">PS / MOOE</th>
            <th style="${head};text-align:right">Remaining</th>
            <th style="${head}">Period</th>
            <th style="${head}">Status</th>
            <th style="${head}">Health</th>
            <th style="${head};text-align:right">Approvals</th>
          </tr></thead>
          <tbody>${projectRows}</tbody>
        </table>
      </body></html>`;

    const printWindow = window.open('', '_blank', 'width=1100,height=760');
    if (!printWindow) {
      setError('Allow pop-ups for this site to export the PDF.');
      return;
    }
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  }

  const detailState = { from: `${location.pathname}${location.search}` };
  const exportDisabled = loading || !summary || rows.length === 0;

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Projects · Program Leader</div>
          <h1 className="program-h1">Projects</h1>
          <p className="program-sub">Externally funded research projects across every funder.</p>
        </div>
        <div className="program-head-actions">
          <button type="button" className="program-btn" onClick={exportPdf} disabled={exportDisabled}>
            Export PDF
          </button>
          <button type="button" className="program-btn" onClick={exportExcel} disabled={exportDisabled || exporting}>
            {exporting ? 'Preparing…' : 'Export Excel'}
          </button>
          <button type="button" className="program-btn pp-soon" disabled title="Available soon">
            New project
          </button>
        </div>
      </div>

      {error && (
        <div className="program-banner error" role="alert">{error}</div>
      )}

      <section className="pp-summary" aria-label="Summary for the current filters">
        <div className="pp-summary-card">
          <div className="pp-summary-label">PROJECTS</div>
          <div className="pp-summary-value">{summary ? summary.projects : DASH}</div>
          <div className="pp-summary-sub">
            {summary ? `${summary.ongoing} ongoing · ${summary.completed} completed` : DASH}
          </div>
        </div>
        <div className="pp-summary-card">
          <div className="pp-summary-label">TOTAL ALLOCATED</div>
          <div className="pp-summary-value">{summary ? peso(summary.allocated) : DASH}</div>
          <div className="pp-summary-sub">
            {summary ? (summary.funder_name || 'PS and MOOE, all funders') : DASH}
          </div>
        </div>
        <div className="pp-summary-card">
          <div className="pp-summary-label">TOTAL SPENT</div>
          <div className="pp-summary-value">{summary ? peso(summary.spent) : DASH}</div>
          <div className="pp-summary-sub">
            {summary ? `${formatPct(summary.spent_pct)} of allocated` : DASH}
          </div>
        </div>
        <div className="pp-summary-card">
          <div className="pp-summary-label">REMAINING</div>
          <div className="pp-summary-value">{summary ? peso(summary.remaining) : DASH}</div>
          <div className="pp-summary-sub">
            {summary ? `${formatPct(summary.remaining_pct)} still available` : DASH}
          </div>
        </div>
      </section>

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

        <div className="program-field">
          <label htmlFor="project-leader">Project leader</label>
          <select
            id="project-leader"
            className="program-select"
            value={leader}
            onChange={(event) => setFilter('leader', event.target.value)}
          >
            <option value="">All project leaders</option>
            {leaders.map((person) => (
              <option key={person.id} value={person.id}>{person.name}</option>
            ))}
          </select>
        </div>

        <div className="program-field pp-field-year">
          <label htmlFor="project-year">Year</label>
          <select
            id="project-year"
            className="program-select"
            value={year}
            onChange={(event) => setFilter('year', event.target.value)}
          >
            <option value="">All years</option>
            {years.map((value) => (
              <option key={value} value={value}>{value}</option>
            ))}
          </select>
        </div>

        <button
          type="button"
          className={`program-btn pp-toggle${atRiskOnly ? ' is-active' : ''}`}
          aria-pressed={atRiskOnly}
          onClick={() => setFilter('risk', atRiskOnly ? '' : '1')}
        >
          {atRiskOnly && <span aria-hidden="true">✓ </span>}
          At risk · {atRiskCount}
        </button>

        {hasFilters && (
          <button type="button" className="pp-clear" onClick={clearFilters}>
            Clear filters
          </button>
        )}
      </div>

      <section className="program-card">
        {loading && !data ? (
          <div className="program-empty">Loading projects…</div>
        ) : rows.length === 0 ? (
          <div className="program-empty pp-empty">
            No projects match these filters.{' '}
            {hasFilters && (
              <button type="button" className="pp-clear" onClick={clearFilters}>Clear filters</button>
            )}
          </div>
        ) : (
          <div className="program-scroll pp-scroll" aria-busy={loading}>
            <table className="program-table pp-table">
              <thead>
                <tr>
                  {sortHeader('title', 'Project')}
                  {sortHeader('spent', 'Spent')}
                  <th className="pp-num">Remaining</th>
                  {sortHeader('period', 'Period')}
                  <th>Status</th>
                  <th>Health</th>
                  <th className="pp-num">Approvals</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((project) => {
                  const closed = project.health === 'Closed';
                  const lastEntry = relativeDate(project.last_entry_date);
                  return (
                    <tr
                      key={project.id}
                      className="pp-row"
                      onClick={(event) => {
                        // Links, buttons and the actions menu handle their own clicks.
                        if (event.target.closest('a, button, [role="menu"]')) return;
                        navigate(`/program-projects/${project.id}`, { state: detailState });
                      }}
                    >
                      <td className="pp-col-project">
                        <Link
                          className="project-name pp-link"
                          to={`/program-projects/${project.id}`}
                          state={detailState}
                        >
                          {project.title || DASH}
                        </Link>
                        <div className="project-code">
                          {project.code || DASH} · Ref. {project.funder_reference || DASH}
                        </div>
                        <div className="pp-meta">
                          {project.funder || DASH} · {project.project_leader || DASH}
                        </div>
                      </td>
                      <td className="pp-col-spent">
                        <div className="pp-spent-head">
                          <b>{pctOrDash(project.spent_pct)}</b>
                          <span>
                            {moneyOrDash(project.spent, compactPeso)} of {moneyOrDash(project.budget, compactPeso)}
                          </span>
                        </div>
                        <div className="program-progress pp-progress" aria-hidden="true">
                          <span style={{ width: `${clampPct(project.spent_pct)}%` }} />
                        </div>
                        <SplitBar label="PS" bucket={project.ps} tone="ps" />
                        <SplitBar label="MOOE" bucket={project.mooe} tone="mooe" />
                      </td>
                      <td className="pp-num">
                        <b>{moneyOrDash(project.remaining, compactPeso)}</b>
                      </td>
                      <td className="pp-col-period">
                        <div>{projectPeriod(project.start_date, project.end_date)}</div>
                        {lastEntry ? (
                          <div className="pp-meta">Last entry: {lastEntry}</div>
                        ) : (
                          <div className="pp-meta pp-none">Last entry: none yet</div>
                        )}
                      </td>
                      <td>{project.status ? <ProgramPill value={project.status} /> : DASH}</td>
                      <td className="pp-col-health">
                        {closed ? (
                          <>
                            <div>{DASH}</div>
                            <div className="pl-health-reason">Not tracked after completion</div>
                          </>
                        ) : (
                          <>
                            {project.health ? <ProgramPill value={project.health} /> : DASH}
                            {project.health_note && (
                              <div className="pl-health-reason">{project.health_note}</div>
                            )}
                          </>
                        )}
                      </td>
                      <td className="pp-num">
                        <div className="pp-approvals">
                          {project.pending_approvals > 0 ? (
                            <Link
                              className="pp-pending"
                              to={`/program-approvals?project=${project.id}&status=pending`}
                            >
                              <span aria-hidden="true">⏳</span>
                              {project.pending_approvals} pending
                            </Link>
                          ) : (
                            <span className="pp-muted">No pending</span>
                          )}
                          <RowActions project={project} detailState={detailState} />
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
