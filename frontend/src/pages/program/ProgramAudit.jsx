import { useCallback, useEffect, useRef, useState } from 'react';
import { DATE_MAX, DATE_MIN, inDateRange } from '../../lib/dateRange';
import { Link, useSearchParams } from 'react-router-dom';
import { fetchProgramAudit } from '../../api/program';
import { PH_TIME_LABEL, formatDateTime } from '../../lib/datetime';
import { projectCode, recordTypeLine, visibleChanges, visibleEntries } from '../../lib/auditDisplay';
import Tip from '../../components/Tip';

const FILTERS = ['action', 'project', 'user', 'from', 'to'];

const TONE_ICON = { success: '✓', danger: '✕', neutral: '•' };

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

/** Where a record opens. Approval requests open on their tab in Approvals. */
function recordHref(link) {
  if (!link) return null;
  if (link.kind === 'approval') {
    const params = new URLSearchParams();
    if (link.tab && link.tab !== 'pending') params.set('tab', link.tab);
    if (link.project_id) params.set('project', link.project_id);
    const query = params.toString();
    return `/program-approvals${query ? `?${query}` : ''}`;
  }
  if (link.kind === 'project' && link.project_id) return `/program-projects/${link.project_id}`;
  if (link.kind === 'user' && link.user_id) return `/program-users?user=${link.user_id}`;
  return null;
}

function ChangeList({ changes, reason }) {
  if (!changes.length && !reason) return null;
  return (
    <ul className="au-changes">
      {changes.map((change, index) => (
        <li key={`${change.label}-${index}`}>
          <span className="au-field">{change.label}:</span>{' '}
          {change.old !== null && <del className="au-old">{change.old}</del>}
          {change.old !== null && change.new !== null && <span className="au-arrow" aria-label="changed to"> → </span>}
          {change.new !== null && <strong className="au-new">{change.new}</strong>}
        </li>
      ))}
      {reason && (
        <li className="au-reason"><span className="au-field">Reason:</span> {reason}</li>
      )}
    </ul>
  );
}

// Display only (lib/auditDisplay.js): the API and the stored entries are unchanged.
function AuditRow({ entry, projects }) {
  const href = recordHref(entry.record?.link);
  const code = projectCode(entry.project, projects);
  return (
    <tr>
      <td className="au-time">
        <time dateTime={entry.created_at}>{formatDateTime(entry.created_at)}</time>
      </td>
      <td>
        {entry.actor ? (
          <>
            <Link className="au-link" to={`/program-users?user=${entry.actor.id}`}>{entry.actor.name}</Link>
            <Tip icon label={`Role of ${entry.actor.name}`} text={entry.actor.role ?? 'No role'} />
          </>
        ) : (
          <Tip text="Automated">System</Tip>
        )}
      </td>
      <td>
        <span className={`au-pill ${entry.action.tone}`}>
          <span aria-hidden="true">{TONE_ICON[entry.action.tone] ?? '•'}</span>
          {entry.action.label}
        </span>
      </td>
      <td>
        {href ? (
          <Link className="au-link" to={href}>{entry.record.label}</Link>
        ) : (
          <span className="au-strong">{entry.record?.label}</span>
        )}
        <div className="au-sub">{recordTypeLine(entry.record?.type)}</div>
      </td>
      <td>
        {entry.project && (
          code ? (
            <>
              <Link className="au-link" to={`/program-projects/${entry.project.id}`}>{code}</Link>
              <Tip icon label={`Project title for ${code}`} text={entry.project.title} />
            </>
          ) : (
            <Link className="au-link" to={`/program-projects/${entry.project.id}`}>{entry.project.title}</Link>
          )
        )}
      </td>
      <td><ChangeList changes={visibleChanges(entry)} reason={entry.reason} /></td>
    </tr>
  );
}

export default function ProgramAudit() {
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = Object.fromEntries(FILTERS.map((key) => [key, searchParams.get(key) ?? '']));
  const requestKey = FILTERS.map((key) => filters[key]).join('|');
  const hasFilters = FILTERS.some((key) => filters[key] !== '');

  // Each result is tagged with the filters it was requested for, so a page
  // of the previous filter is never shown under new filters.
  const [result, setResult] = useState(null); // { key, entries, meta, options }
  const [failure, setFailure] = useState(null); // { key, message }
  const [attempt, setAttempt] = useState(0);
  const [moreBusy, setMoreBusy] = useState(false);
  const [moreError, setMoreError] = useState('');
  const [options, setOptions] = useState({ actions: [], projects: [], users: [] });
  const controllerRef = useRef(null);

  const load = useCallback(async () => {
    controllerRef.current?.abort();
    const controller = new AbortController();
    controllerRef.current = controller;
    const params = Object.fromEntries(requestKey.split('|').map((value, index) => [FILTERS[index], value]));
    try {
      const data = await fetchProgramAudit({ ...params, page: 1 }, { signal: controller.signal });
      if (controllerRef.current !== controller) return;
      setResult({ key: requestKey, entries: data.entries ?? [], meta: data.meta ?? {} });
      if (data.options) setOptions(data.options);
      setFailure(null);
      setMoreError('');
    } catch (error) {
      if (controller.signal.aborted || controllerRef.current !== controller) return;
      setFailure({ key: requestKey, message: messageFor(error, 'Unable to load the audit trail.') });
    }
  }, [requestKey]);

  // attempt: "Try again" reloads the same filters.
  useEffect(() => {
    load();
    return () => controllerRef.current?.abort();
  }, [load, attempt]);

  async function loadMore() {
    if (!result || moreBusy) return;
    setMoreBusy(true);
    setMoreError('');
    const key = result.key;
    try {
      const data = await fetchProgramAudit({ ...filters, page: (result.meta.page ?? 1) + 1 });
      setResult((current) => (current && current.key === key
        ? { ...current, entries: [...current.entries, ...(data.entries ?? [])], meta: data.meta ?? current.meta }
        : current));
    } catch (error) {
      setMoreError(messageFor(error, 'Unable to load more entries.'));
    } finally {
      setMoreBusy(false);
    }
  }

  // Start from the live URL (updated immediately by each replace), so two
  // quick changes such as From then To both stick. React Router's own
  // searchParams only catch up on the next render.
  function setFilter(key, value) {
    const next = new URLSearchParams(window.location.search);
    if (value) next.set(key, value);
    else next.delete(key);
    setSearchParams(next, { replace: true });
  }

  function clearFilters() {
    const next = new URLSearchParams(window.location.search);
    FILTERS.forEach((key) => next.delete(key));
    setSearchParams(next, { replace: true });
  }

  const current = result?.key === requestKey ? result : null;
  const error = failure?.key === requestKey ? failure.message : '';
  const loading = !current && !error;
  const entries = current?.entries ?? [];
  const total = current?.meta?.total ?? 0;

  // Keep a linked project or user selectable even before options arrive.
  const projectOptions = options.projects ?? [];
  const userOptions = options.users ?? [];

  return (
    <>
      <div className="program-page-head">
        <div>
          <h1 className="program-h1">Audit trail</h1>
        </div>
      </div>

      <div className="program-filter-row au-filters" role="group" aria-label="Audit trail filters">
        <div className="program-field">
          <label htmlFor="audit-action">Action</label>
          <select id="audit-action" className="program-select" value={filters.action} onChange={(event) => setFilter('action', event.target.value)}>
            <option value="">All actions</option>
            {(options.actions ?? []).map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </div>
        <div className="program-field">
          <label htmlFor="audit-project">Project</label>
          <select id="audit-project" className="program-select" value={filters.project} onChange={(event) => setFilter('project', event.target.value)}>
            <option value="">All projects</option>
            {filters.project && !projectOptions.some((p) => String(p.id) === filters.project) && (
              <option value={filters.project}>Selected project</option>
            )}
            {projectOptions.map((project) => (
              <option key={project.id} value={String(project.id)}>{project.title}</option>
            ))}
          </select>
        </div>
        <div className="program-field">
          <label htmlFor="audit-user">User</label>
          <select id="audit-user" className="program-select" value={filters.user} onChange={(event) => setFilter('user', event.target.value)}>
            <option value="">All users</option>
            {filters.user && !userOptions.some((u) => String(u.id) === filters.user) && (
              <option value={filters.user}>Selected user</option>
            )}
            {userOptions.map((user) => (
              <option key={user.id} value={String(user.id)}>{user.name}</option>
            ))}
          </select>
        </div>
        <div className="program-field au-date">
          <label htmlFor="audit-from">From</label>
          <input id="audit-from" className="program-input" type="date" min={DATE_MIN} value={filters.from} max={filters.to || DATE_MAX} onChange={(event) => { if (inDateRange(event.target.value)) setFilter('from', event.target.value); }} />
        </div>
        <div className="program-field au-date">
          <label htmlFor="audit-to">To</label>
          <input id="audit-to" className="program-input" type="date" value={filters.to} min={filters.from || DATE_MIN} max={DATE_MAX} onChange={(event) => { if (inDateRange(event.target.value)) setFilter('to', event.target.value); }} />
        </div>
        {hasFilters && (
          <button type="button" className="pp-clear" onClick={clearFilters}>Clear filters</button>
        )}
      </div>

      {error && (
        <div className="program-banner error" role="alert">
          {error}{' '}
          <button
            type="button"
            className="program-btn sm"
            onClick={() => { setFailure(null); setAttempt((value) => value + 1); }}
          >
            Try again
          </button>
        </div>
      )}

      <section className="program-card au-card" aria-busy={loading} aria-label="Audit entries">
        {loading ? (
          <div className="program-empty">Loading audit entries…</div>
        ) : error ? null : entries.length === 0 ? (
          <div className="pa-empty">
            <span>{hasFilters ? 'No audit entries match these filters.' : 'No audit entries yet.'}</span>
            {hasFilters && <button type="button" className="pp-clear" onClick={clearFilters}>Clear filters</button>}
          </div>
        ) : (
          <>
            <div className="program-scroll">
              <table className="program-table au-table">
                <thead>
                  <tr>
                    <th scope="col">Time</th>
                    <th scope="col">User</th>
                    <th scope="col">Action</th>
                    <th scope="col">Record</th>
                    <th scope="col">Project</th>
                    <th scope="col">Change</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleEntries(entries).map((entry) => <AuditRow key={entry.id} entry={entry} projects={projectOptions} />)}
                </tbody>
              </table>
            </div>

            <div className="au-foot">
              <span className="au-count" aria-live="polite">Showing {entries.length} of {total} entries</span>
              {current.meta.has_more && (
                <button type="button" className="program-btn" onClick={loadMore} disabled={moreBusy}>
                  {moreBusy ? 'Loading…' : 'Load more'}
                </button>
              )}
              {moreError && (
                <span className="program-error" role="alert">
                  {moreError}{' '}
                  <button type="button" className="pp-clear" onClick={loadMore}>Try again</button>
                </span>
              )}
            </div>
          </>
        )}
        <p className="au-tz">Times in {PH_TIME_LABEL}.</p>
      </section>
    </>
  );
}
