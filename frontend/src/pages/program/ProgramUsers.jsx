import { useCallback, useEffect, useRef, useState } from 'react';
import LeaderConflicts from '../../components/program/LeaderConflicts';
import { conflictFor } from '../../lib/leaderRule';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import {
  assignToProject,
  createProgramUser,
  fetchPendingRegistrations,
  fetchProgramUsers,
  removeProjectAssignment,
  updateProgramUserDetails,
  fixProjectAssignment,
  transferProgramLeader,
  updateProgramUserRole,
  updateProgramUserStatus,
} from '../../api/program';
import ProgramAddUserModal from '../../components/program/ProgramAddUserModal';
import ProgramAssignUserModal from '../../components/program/ProgramAssignUserModal';
import ProgramPill from '../../components/program/ProgramPill';
import RegistrationReviewPanel from '../../components/program/RegistrationReviewPanel';
import { useProgramRefresh } from '../../layouts/ProgramLayout';
import {
  AssignmentsDialog,
  ChangeRoleDialog,
  EditDetailsDialog,
  ManageMenu,
  MismatchWarning,
  StatusDialog,
  TransferProgramLeaderDialog,
} from '../../components/program/ProgramUserManage';
import { formatDate } from '../../lib/datetime';
import { ALL_ACCOUNT_ROLE_OPTIONS } from '../../lib/roles';

const FILTERS = ['q', 'role', 'project', 'status', 'user'];

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

function fieldErrorsFrom(error) {
  const errors = error?.response?.data?.errors ?? {};
  return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages?.[0]]));
}

function RoleLabel({ slug, label }) {
  return <span className={`pu-role ${slug ?? 'none'}`}>{label ?? 'No role'}</span>;
}

function Assignments({ user, onFix, fixing }) {
  if (user.program_wide) {
    return (
      <div className="pu-assign">
        <span className="au-strong">All projects</span>
        <div className="au-sub">Program-wide role</div>
      </div>
    );
  }
  if (!user.assignments.length) return <span className="au-muted">Not assigned yet</span>;
  return (
    <ul className="pu-assign-list">
      {user.assignments.map((assignment) => {
        const key = `${user.id}-${assignment.project_id}`;
        return (
          <li key={assignment.project_id}>
            <Link className="au-link" to={`/program-projects/${assignment.project_id}`}>
              {assignment.project} · {assignment.project_code}
            </Link>
            <div className="au-sub">as {assignment.role_label}</div>
            {assignment.role_mismatch && (
              <div className="pu-asg-fix">
                <MismatchWarning assignment={assignment} user={user} />
                <button type="button" className="program-btn sm" disabled={fixing === key} onClick={() => onFix(user, assignment)}>
                  {fixing === key ? 'Fixing…' : 'Fix'}
                  <span className="pa-sr-only"> {user.name} on {assignment.project}: make it {user.role_label}</span>
                </button>
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

export default function ProgramUsers() {
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = Object.fromEntries(FILTERS.map((key) => [key, searchParams.get(key) ?? '']));
  const requestKey = FILTERS.map((key) => filters[key]).join('|');
  const hasFilters = ['q', 'role', 'project', 'status', 'user'].some((key) => filters[key] !== '');

  // ---- pending registrations: each card opens the Review panel (?review=<id>)
  const { refreshRegistrations } = useProgramRefresh();
  const { signOut } = useAuth();
  const navigate = useNavigate();
  const [pending, setPending] = useState([]);
  const [reviewOptions, setReviewOptions] = useState({ roles: [], project_roles: [], projects: [] });
  const [pendingLoading, setPendingLoading] = useState(true);
  const [pendingError, setPendingError] = useState('');
  const reviewId = searchParams.get('review') ?? '';

  // ---- people list: each result is tagged with the filters it belongs to
  const [result, setResult] = useState(null); // { key, users, meta }
  const [failure, setFailure] = useState(null); // { key, message }
  const [options, setOptions] = useState({ roles: [], projects: [], assignable_users: [] });
  // Project Leaders leading more than one active project (flagged, never changed automatically).
  const [leaderConflicts, setLeaderConflicts] = useState([]);
  const [reloadToken, setReloadToken] = useState(0);
  const [moreBusy, setMoreBusy] = useState(false);
  const controllerRef = useRef(null);

  // ---- dialogs
  const [addOpen, setAddOpen] = useState(false);
  const [addBusy, setAddBusy] = useState(false);
  const [addError, setAddError] = useState('');
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignBusy, setAssignBusy] = useState(false);
  const [assignError, setAssignError] = useState('');
  const [assignDefaultUserId, setAssignDefaultUserId] = useState('');
  const [dialog, setDialog] = useState(null); // { kind: 'edit' | 'role' | 'status', user }
  const [dialogBusy, setDialogBusy] = useState(false);
  const [dialogError, setDialogError] = useState('');
  const [dialogFieldErrors, setDialogFieldErrors] = useState({});

  const [toast, setToast] = useState(null);
  const [searchText, setSearchText] = useState(filters.q);

  const showToast = (msg) => {
    setToast({ msg });
    window.setTimeout(() => setToast(null), 4000);
  };

  const loadPending = useCallback(async () => {
    setPendingLoading(true);
    setPendingError('');
    try {
      const payload = await fetchPendingRegistrations();
      const list = Array.isArray(payload?.users) ? payload.users : [];
      setPending(list);
      setReviewOptions({
        roles: Array.isArray(payload?.roles) ? payload.roles : [],
        project_roles: Array.isArray(payload?.project_roles) ? payload.project_roles : [],
        projects: Array.isArray(payload?.projects) ? payload.projects : [],
      });
    } catch (error) {
      setPendingError(messageFor(error, 'Unable to load pending registrations.'));
    } finally {
      setPendingLoading(false);
    }
  }, []);

  const loadUsers = useCallback(async () => {
    controllerRef.current?.abort();
    const controller = new AbortController();
    controllerRef.current = controller;
    const params = Object.fromEntries(requestKey.split('|').map((value, index) => [FILTERS[index], value]));
    try {
      const data = await fetchProgramUsers({ ...params, page: 1 }, { signal: controller.signal });
      if (controllerRef.current !== controller) return;
      setResult({ key: requestKey, users: data.users ?? [], meta: data.meta ?? {} });
      setLeaderConflicts(Array.isArray(data.leader_conflicts) ? data.leader_conflicts : []);
      setOptions({
        roles: data.roles ?? [],
        projects: data.projects ?? [],
        assignable_users: data.assignable_users ?? [],
      });
      setFailure(null);
    } catch (error) {
      if (controller.signal.aborted || controllerRef.current !== controller) return;
      setFailure({ key: requestKey, message: messageFor(error, 'Unable to load accounts.') });
    }
  }, [requestKey]);

  useEffect(() => {
    loadPending();
  }, [loadPending]);

  // reloadToken: reload the same filters after a change.
  useEffect(() => {
    loadUsers();
    return () => controllerRef.current?.abort();
  }, [loadUsers, reloadToken]);

  // Search applies 300 ms after typing stops.
  useEffect(() => {
    const term = searchText.trim();
    if (term === filters.q) return undefined;
    const timer = window.setTimeout(() => {
      const next = new URLSearchParams(window.location.search);
      if (term) next.set('q', term);
      else next.delete('q');
      next.delete('user');
      setSearchParams(next, { replace: true });
    }, 300);
    return () => window.clearTimeout(timer);
  }, [searchText, filters.q, setSearchParams]);

  function setFilter(key, value) {
    // Start from the live URL so two quick changes both stick.
    const next = new URLSearchParams(window.location.search);
    if (value) next.set(key, value);
    else next.delete(key);
    if (key !== 'user') next.delete('user');
    setSearchParams(next, { replace: true });
  }

  function clearFilters() {
    const next = new URLSearchParams(window.location.search);
    FILTERS.forEach((key) => next.delete(key));
    setSearchText('');
    setSearchParams(next, { replace: true });
  }

  async function loadMore() {
    if (!result || moreBusy) return;
    setMoreBusy(true);
    const key = result.key;
    try {
      const data = await fetchProgramUsers({ ...filters, page: (result.meta.page ?? 1) + 1 });
      setResult((current) => (current && current.key === key
        ? { ...current, users: [...current.users, ...(data.users ?? [])], meta: data.meta ?? current.meta }
        : current));
    } catch (error) {
      showToast(messageFor(error, 'Unable to load more accounts.'));
    } finally {
      setMoreBusy(false);
    }
  }

  const refresh = () => setReloadToken((value) => value + 1);

  function openReview(id) {
    const next = new URLSearchParams(window.location.search);
    if (id) next.set('review', String(id));
    else next.delete('review');
    setSearchParams(next, { replace: !id });
  }

  async function handleDecided(message) {
    openReview(null);
    showToast(message);
    refreshRegistrations();
    await loadPending();
    refresh();
  }

  // One-click "Fix" for an older row whose role differs from the account role.
  const [fixingKey, setFixingKey] = useState('');
  async function handleFix(user, assignment) {
    setFixingKey(`${user.id}-${assignment.project_id}`);
    try {
      await fixProjectAssignment(user.id, assignment.project_id);
      showToast(`${user.name} on ${assignment.project} is now ${user.role_label}.`);
      refresh();
    } catch (error) {
      showToast(messageFor(error, 'Could not save. Please try again.'));
    } finally {
      setFixingKey('');
    }
  }

  async function handleCreateUser(payload) {
    setAddBusy(true);
    setAddError('');
    try {
      const result = await createProgramUser(payload);
      setAddOpen(false);
      showToast(result?.setup_email_sent === false
        ? (result.message || 'User created, but the setup email could not be sent.')
        : 'User created. A password setup link was emailed.');
      refresh();
    } catch (error) {
      setAddError(messageFor(error, 'Unable to create the user.'));
    } finally {
      setAddBusy(false);
    }
  }

  async function handleAssign(payload) {
    setAssignBusy(true);
    setAssignError('');
    try {
      await assignToProject(payload);
      setAssignOpen(false);
      showToast('User assigned to project.');
      refresh();
    } catch (error) {
      setAssignError(messageFor(error, 'Unable to assign the user.'));
    } finally {
      setAssignBusy(false);
    }
  }

  function openDialog(kind, user) {
    setDialogError('');
    setDialogFieldErrors({});
    setDialog({ kind, user });
  }

  async function runDialog(request, success) {
    setDialogBusy(true);
    setDialogError('');
    setDialogFieldErrors({});
    try {
      await request();
      setDialog(null);
      showToast(success);
      refresh();
    } catch (error) {
      const fields = fieldErrorsFrom(error);
      setDialogFieldErrors(fields);
      setDialogError(Object.keys(fields).length ? '' : messageFor(error, 'Unable to save this change.'));
    } finally {
      setDialogBusy(false);
    }
  }

  // Transfer Program Leader role: on success this account is deactivated
  // and signed out by the server; clear the local session and go to sign-in.
  async function runTransfer(payload) {
    setDialogBusy(true);
    setDialogError('');
    setDialogFieldErrors({});
    try {
      const data = await transferProgramLeader(payload);
      setDialog(null);
      await signOut();
      navigate('/login', {
        replace: true,
        state: { notice: data?.message || 'Program Leader role transferred. Your account is now deactivated.' },
      });
    } catch (error) {
      const fields = fieldErrorsFrom(error);
      setDialogFieldErrors(fields);
      setDialogError(Object.keys(fields).length ? '' : messageFor(error, 'Unable to transfer the Program Leader role.'));
      setDialogBusy(false);
    }
  }

  const current = result?.key === requestKey ? result : null;
  const error = failure?.key === requestKey ? failure.message : '';
  const loading = !current && !error;
  const users = current?.users ?? [];
  const total = current?.meta?.total ?? 0;
  const highlightUserId = filters.user;
  const highlightMissing = highlightUserId !== '' && current && current.meta.focus_found === false;

  // ?user= scrolls to and highlights that person once the list has loaded.
  useEffect(() => {
    if (!highlightUserId || !current) return;
    document.querySelector(`tr[data-user-id="${CSS.escape(highlightUserId)}"]`)
      ?.scrollIntoView({ block: 'center' });
  }, [highlightUserId, current]);

  // ?review=<id> (also from the bell) opens that applicant's Review panel.
  const reviewUser = reviewId ? pending.find((user) => String(user.id) === reviewId) ?? null : null;
  // Opened from the bell while this page was already loaded: the list may
  // predate the request, so reload it once before saying it is gone.
  const [reloadedFor, setReloadedFor] = useState('');
  const needsReload = reviewId !== '' && !pendingLoading && !reviewUser && reloadedFor !== reviewId;
  useEffect(() => {
    if (!needsReload) return;
    setReloadedFor(reviewId);
    loadPending();
  }, [needsReload, reviewId, loadPending]);
  const reviewGone = reviewId !== '' && !pendingLoading && !pendingError && !reviewUser && !needsReload;

  const statusValue = filters.status || (filters.user ? 'all' : 'active');

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Users and roles · Program Leader</div>
          <h1 className="program-h1">Users and roles</h1>
          <p className="program-sub">Create, update and deactivate accounts, and assign people to projects.</p>
        </div>
        <div className="program-head-actions">
          <button type="button" className="program-btn" onClick={() => { setAddError(''); setAddOpen(true); }}>
            Add user
          </button>
          <button type="button" className="program-btn" onClick={() => openDialog('transfer', null)}>
            Transfer Program Leader role
          </button>
          {/* Assignment happens from Manage > Edit project assignments and the project page Team "Add member". */}
        </div>
      </div>

      <LeaderConflicts conflicts={leaderConflicts} />

      <div aria-live="polite">
        {toast && <div className="program-banner ok">{toast.msg}</div>}
        {pendingError && (
          <div className="program-banner error" role="alert">
            {pendingError}{' '}
            <button type="button" className="program-btn sm" onClick={loadPending}>Try again</button>
          </div>
        )}
        {reviewGone && (
          <div className="program-banner info" role="status">
            This account request was already approved or rejected.{' '}
            <button type="button" className="program-btn sm" onClick={() => openReview(null)}>Dismiss</button>
          </div>
        )}
      </div>

      {!pendingLoading && pending.length === 0 ? (
        <section className="program-card pu-pending-empty" aria-label="Pending registrations">
          <span className="program-card-title">Pending registrations</span>
          <span className="pu-count">0</span>
          <span className="au-muted">No registrations waiting for review.</span>
        </section>
      ) : (
        <section className="program-card">
          <div className="program-card-head">
            <div>
              <div className="program-card-title">Pending registrations</div>
              <div className="program-card-sub">Review each request: approve with an account role and project assignments, or reject with a reason. The applicant is emailed either way.</div>
            </div>
          </div>

          {pendingLoading ? (
            <div className="program-empty">Loading pending registrations…</div>
          ) : (
            <div className="program-note">
              <div className="program-note-title">
                {pending.length} pending registration{pending.length === 1 ? '' : 's'}
              </div>
              <div className="program-note-sub">
                Project assignments are optional; you can also add them later from Manage.
              </div>

              {pending.map((user) => (
                  <div className="program-pending-row" key={user.id}>
                    <div className="program-pending-main">
                      <div className="program-row-title">
                        <span>{user.name}</span>
                        <span className="program-muted">{user.employee_id || '—'}</span>
                      </div>
                      <div className="program-row-sub">
                        {`${user.position || '—'} · ${[user.college, user.department].filter(Boolean).join(', ') || '—'}`}
                      </div>
                      <div className="program-row-sub">
                        {user.email} · requested {formatDate(user.created_at)}
                      </div>
                    </div>

                    <div className="program-pending-actions">
                      <button
                        type="button"
                        className="program-btn primary"
                        aria-label={`Review ${user.name}`}
                        onClick={() => openReview(user.id)}
                      >
                        Review
                      </button>
                    </div>
                  </div>
              ))}
            </div>
          )}
        </section>
      )}

      <div className="program-filter-row pu-filters" role="group" aria-label="Account filters">
        <div className="program-field pu-search">
          <label htmlFor="pu-search">Search</label>
          <input
            id="pu-search"
            className="program-input"
            type="search"
            placeholder="Name or employee ID"
            value={searchText}
            onChange={(event) => setSearchText(event.target.value)}
          />
        </div>
        <div className="program-field">
          <label htmlFor="pu-role">Account role</label>
          <select id="pu-role" className="program-select" value={filters.role} onChange={(event) => setFilter('role', event.target.value)}>
            <option value="">All roles</option>
            {ALL_ACCOUNT_ROLE_OPTIONS.map((role) => (
              <option key={role.slug} value={role.slug}>{role.label}</option>
            ))}
          </select>
        </div>
        <div className="program-field">
          <label htmlFor="pu-project">Project</label>
          <select id="pu-project" className="program-select" value={filters.project} onChange={(event) => setFilter('project', event.target.value)}>
            <option value="">All projects</option>
            {options.projects.map((project) => (
              <option key={project.id} value={String(project.id)}>{project.title}</option>
            ))}
          </select>
        </div>
        <div className="program-field">
          <label htmlFor="pu-status">Status</label>
          <select
            id="pu-status"
            className="program-select"
            value={statusValue}
            onChange={(event) => setFilter('status', event.target.value === 'active' ? '' : event.target.value)}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="all">All</option>
          </select>
        </div>
        {hasFilters && (
          <button type="button" className="pp-clear" onClick={clearFilters}>Clear filters</button>
        )}
      </div>

      {error && (
        <div className="program-banner error" role="alert">
          {error}{' '}
          <button type="button" className="program-btn sm" onClick={refresh}>Try again</button>
        </div>
      )}

      <section className="program-card" aria-busy={loading} aria-label="Accounts">
        {highlightMissing && (
          <div className="program-banner info" role="status">
            The person you opened does not match these filters, or is not an approved account (for example, a rejected registration).
          </div>
        )}

        {loading ? (
          <div className="program-empty">Loading accounts…</div>
        ) : error ? null : users.length === 0 ? (
          <div className="pa-empty">
            <span>{hasFilters ? 'No accounts match these filters.' : 'No accounts yet.'}</span>
            {hasFilters && <button type="button" className="pp-clear" onClick={clearFilters}>Clear filters</button>}
          </div>
        ) : (
          <>
            <div className="program-scroll">
              <table className="program-table pu-table">
                <thead>
                  <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Employee ID</th>
                    <th scope="col">Account role</th>
                    <th scope="col">Project assignments</th>
                    <th scope="col">Last login</th>
                    <th scope="col">Status</th>
                    <th scope="col" className="r">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => {
                    const highlighted = String(user.id) === highlightUserId;
                    return (
                      <tr
                        key={user.id}
                        className={highlighted ? 'pu-highlight' : undefined}
                        data-user-id={user.id}
                        aria-current={highlighted ? 'true' : undefined}
                      >
                        <td>
                          <div className="pu-name">
                            <span className="au-strong">{user.name}</span>
                            {user.is_self && <span className="pu-you">You</span>}
                          </div>
                          <div className="au-sub">{user.position || '—'}</div>
                          {conflictFor(leaderConflicts, user.id) && (
                            <div className="pu-conflict"><span aria-hidden="true">⚠ </span>{conflictFor(leaderConflicts, user.id).message}</div>
                          )}
                        </td>
                        <td className="num">{user.employee_id || '—'}</td>
                        <td><RoleLabel slug={user.role_slug} label={user.role_label} /></td>
                        <td><Assignments user={user} onFix={handleFix} fixing={fixingKey} /></td>
                        <td className="num">{formatDate(user.last_login_at)}</td>
                        <td><ProgramPill value={user.is_active ? 'Active' : 'Inactive'} /></td>
                        <td className="r">
                          <ManageMenu
                            user={user}
                            onEdit={() => openDialog('edit', user)}
                            onRole={() => openDialog('role', user)}
                            onStatus={() => openDialog('status', user)}
                            onAssignments={() => openDialog('assignments', user)}
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="au-foot">
              <span className="au-count" aria-live="polite">Showing {users.length} of {total} accounts</span>
              {current.meta.has_more && (
                <button type="button" className="program-btn" onClick={loadMore} disabled={moreBusy}>
                  {moreBusy ? 'Loading…' : 'Load more'}
                </button>
              )}
            </div>
          </>
        )}
      </section>

      <ProgramAddUserModal
        open={addOpen}
        busy={addBusy}
        error={addError}
        onSave={handleCreateUser}
        onClose={() => { if (!addBusy) setAddOpen(false); }}
      />
      {/* Mounted only while open, so every open starts clean. */}
      {assignOpen && <ProgramAssignUserModal
        key={assignDefaultUserId || 'assign-new'}
        users={options.assignable_users}
        projects={options.projects}
        busy={assignBusy}
        error={assignError}
        defaultUserId={assignDefaultUserId}
        onSave={handleAssign}
        onClose={() => { if (!assignBusy) setAssignOpen(false); }}
      />}

      {reviewUser && (
        <RegistrationReviewPanel
          key={reviewUser.id}
          user={reviewUser}
          projects={reviewOptions.projects}
          accountRoles={reviewOptions.roles}
          onClose={() => openReview(null)}
          onDecided={handleDecided}
        />
      )}

      {dialog?.kind === 'edit' && (
        <EditDetailsDialog
          user={dialog.user}
          busy={dialogBusy}
          error={dialogError}
          fieldErrors={dialogFieldErrors}
          onClose={() => { if (!dialogBusy) setDialog(null); }}
          onSave={(payload) => runDialog(() => updateProgramUserDetails(dialog.user.id, payload), 'Account details updated.')}
        />
      )}
      {/* Hidden while its "Add to a project" step (the assign dialog) is open; it comes back after. */}
      {dialog?.kind === 'assignments' && !assignOpen && (
        <AssignmentsDialog
          // The person's row from the latest list, so the dialog shows each change.
          user={users.find((user) => user.id === dialog.user.id) ?? dialog.user}
          onAdd={() => {
            setAssignError('');
            setAssignDefaultUserId(String(dialog.user.id));
            setAssignOpen(true);
          }}
          onClose={() => setDialog(null)}
          onChanged={refresh}
          onFix={(projectId) => fixProjectAssignment(dialog.user.id, projectId)}
          onRemove={(projectId, reason) => removeProjectAssignment(dialog.user.id, projectId, reason)}
        />
      )}
      {dialog?.kind === 'role' && (
        <ChangeRoleDialog
          user={dialog.user}
          busy={dialogBusy}
          error={dialogError}
          onClose={() => { if (!dialogBusy) setDialog(null); }}
          onSave={(payload) => runDialog(() => updateProgramUserRole(dialog.user.id, payload), 'Account role changed.')}
        />
      )}
      {dialog?.kind === 'transfer' && (
        <TransferProgramLeaderDialog
          candidates={options.assignable_users}
          busy={dialogBusy}
          error={dialogError}
          fieldErrors={dialogFieldErrors}
          onClose={() => { if (!dialogBusy) setDialog(null); }}
          onSave={runTransfer}
        />
      )}
      {dialog?.kind === 'status' && (
        <StatusDialog
          user={dialog.user}
          busy={dialogBusy}
          error={dialogError}
          onClose={() => { if (!dialogBusy) setDialog(null); }}
          onSave={(payload) => runDialog(
            () => updateProgramUserStatus(dialog.user.id, payload),
            payload.is_active ? 'Account reactivated.' : 'Account deactivated. The user has been signed out.',
          )}
        />
      )}
    </>
  );
}
