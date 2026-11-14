import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  assignToProject,
  createProgramUser,
  decideRegistration,
  fetchPendingRegistrations,
  fetchProgramUsers,
  removeProjectAssignment,
  updateProgramUserDetails,
  updateProjectAssignment,
  updateProgramUserRole,
  updateProgramUserStatus,
} from '../../api/program';
import ProgramAddUserModal from '../../components/program/ProgramAddUserModal';
import ProgramAssignUserModal from '../../components/program/ProgramAssignUserModal';
import ProgramPill from '../../components/program/ProgramPill';
import {
  AssignmentsDialog,
  ChangeRoleDialog,
  EditDetailsDialog,
  ManageMenu,
  StatusDialog,
} from '../../components/program/ProgramUserManage';
import { formatDate } from '../../lib/datetime';
import { ACCOUNT_ROLE_OPTIONS } from '../../lib/roles';

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

function Assignments({ user }) {
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
      {user.assignments.map((assignment) => (
        <li key={assignment.project_id}>
          <Link className="au-link" to={`/program-projects/${assignment.project_id}`}>
            {assignment.project} · {assignment.project_code}
          </Link>
          <div className="au-sub">as {assignment.role_label}</div>
        </li>
      ))}
    </ul>
  );
}

export default function ProgramUsers() {
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = Object.fromEntries(FILTERS.map((key) => [key, searchParams.get(key) ?? '']));
  const requestKey = FILTERS.map((key) => filters[key]).join('|');
  const hasFilters = ['q', 'role', 'project', 'status', 'user'].some((key) => filters[key] !== '');

  // ---- pending registrations (unchanged flow)
  const [pending, setPending] = useState([]);
  const [pendingRoleOptions, setPendingRoleOptions] = useState([]);
  const [pendingRoles, setPendingRoles] = useState({});
  const [pendingLoading, setPendingLoading] = useState(true);
  const [pendingError, setPendingError] = useState('');
  const [approvingId, setApprovingId] = useState(null);
  const [rejectingId, setRejectingId] = useState(null);
  const [rejectReasons, setRejectReasons] = useState({});
  const [rejectBusy, setRejectBusy] = useState(false);

  // ---- people list: each result is tagged with the filters it belongs to
  const [result, setResult] = useState(null); // { key, users, meta }
  const [failure, setFailure] = useState(null); // { key, message }
  const [options, setOptions] = useState({ roles: [], projects: [], assignable_users: [] });
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
      setPendingRoleOptions(Array.isArray(payload?.roles) ? payload.roles : []);
      setPendingRoles((previous) => {
        const next = { ...previous };
        list.forEach((user) => {
          if (!next[user.id]) next[user.id] = 'project-leader';
        });
        return next;
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

  async function handleApprove(user) {
    setApprovingId(user.id);
    setPendingError('');
    try {
      await decideRegistration(user.id, {
        decision: 'approved',
        role: pendingRoles[user.id] || 'project-leader',
      });
      showToast(`${user.name} approved. You can now assign them to a project.`);
      await loadPending();
      refresh();
    } catch (error) {
      setPendingError(messageFor(error, 'Unable to approve this registration.'));
    } finally {
      setApprovingId(null);
    }
  }

  async function handleReject(user) {
    const reason = (rejectReasons[user.id] || '').trim();
    if (!reason) {
      setPendingError('A rejection reason is required.');
      return;
    }
    setRejectBusy(true);
    setPendingError('');
    try {
      await decideRegistration(user.id, { decision: 'rejected', remarks: reason });
      setRejectingId(null);
      setRejectReasons((previous) => ({ ...previous, [user.id]: '' }));
      showToast(`${user.name}'s registration was rejected.`);
      await loadPending();
    } catch (error) {
      setPendingError(messageFor(error, 'Unable to reject this registration.'));
    } finally {
      setRejectBusy(false);
    }
  }

  async function handleCreateUser(payload) {
    setAddBusy(true);
    setAddError('');
    try {
      await createProgramUser(payload);
      setAddOpen(false);
      showToast('User created. A password setup link was emailed.');
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
          <button type="button" className="program-btn primary" onClick={() => { setAssignError(''); setAssignDefaultUserId(''); setAssignOpen(true); }}>
            Assign to project
          </button>
        </div>
      </div>

      <div aria-live="polite">
        {toast && <div className="program-banner ok">{toast.msg}</div>}
        {pendingError && <div className="program-banner error" role="alert">{pendingError}</div>}
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
              <div className="program-card-sub">Approve with a role, or reject with a reason. The user is emailed either way.</div>
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
                Registration role options are limited to project roles. Use Manage afterwards to change the account role.
              </div>

              {pending.map((user) => {
                const isApproving = approvingId === user.id;
                const isRejecting = rejectingId === user.id;
                const assignedRole = pendingRoles[user.id] || 'project-leader';

                return (
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

                      {isRejecting && (
                        <div className="program-reject" style={{ marginTop: 12 }}>
                          <div className="program-field">
                            <label htmlFor={`pending-reject-${user.id}`}>Reason for rejection (required)</label>
                            <textarea
                              id={`pending-reject-${user.id}`}
                              className="program-textarea"
                              value={rejectReasons[user.id] || ''}
                              onChange={(event) => setRejectReasons((previous) => ({ ...previous, [user.id]: event.target.value }))}
                              placeholder="Tell the user why this registration was rejected"
                            />
                          </div>
                          <div className="program-reject-actions">
                            <button type="button" className="program-btn" onClick={() => setRejectingId(null)} disabled={rejectBusy}>
                              Cancel
                            </button>
                            <button type="button" className="program-btn primary" onClick={() => handleReject(user)} disabled={rejectBusy}>
                              {rejectBusy ? 'Rejecting…' : 'Confirm rejection'}
                            </button>
                          </div>
                        </div>
                      )}
                    </div>

                    <div className="program-pending-actions">
                      <div className="program-field">
                        <label htmlFor={`pending-role-${user.id}`}>Role</label>
                        <select
                          id={`pending-role-${user.id}`}
                          className="program-select"
                          value={assignedRole}
                          disabled={isApproving}
                          onChange={(event) => setPendingRoles((previous) => ({ ...previous, [user.id]: event.target.value }))}
                        >
                          {pendingRoleOptions.map((role) => (
                            <option key={role.slug} value={role.slug}>{role.label}</option>
                          ))}
                        </select>
                      </div>
                      <button type="button" className="program-btn primary" disabled={isApproving} onClick={() => handleApprove(user)}>
                        {isApproving ? 'Approving…' : 'Approve'}
                      </button>
                      <button
                        type="button"
                        className="program-btn"
                        disabled={isApproving}
                        onClick={() => {
                          setRejectingId(isRejecting ? null : user.id);
                          setRejectReasons((previous) => ({ ...previous, [user.id]: '' }));
                        }}
                      >
                        Reject
                      </button>
                    </div>
                  </div>
                );
              })}
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
            {ACCOUNT_ROLE_OPTIONS.map((role) => (
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
                        </td>
                        <td className="num">{user.employee_id || '—'}</td>
                        <td><RoleLabel slug={user.role_slug} label={user.role_label} /></td>
                        <td><Assignments user={user} /></td>
                        <td className="num">{formatDate(user.last_login_at)}</td>
                        <td><ProgramPill value={user.is_active ? 'Active' : 'Inactive'} /></td>
                        <td className="r">
                          <ManageMenu
                            user={user}
                            onEdit={() => openDialog('edit', user)}
                            onRole={() => openDialog('role', user)}
                            onStatus={() => openDialog('status', user)}
                            onAssignments={() => openDialog('assignments', user)}
                            onAssign={() => {
                              setAssignError('');
                              setAssignDefaultUserId(String(user.id));
                              setAssignOpen(true);
                            }}
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
      <ProgramAssignUserModal
        key={assignDefaultUserId || 'assign-new'}
        open={assignOpen}
        users={options.assignable_users}
        projects={options.projects}
        busy={assignBusy}
        error={assignError}
        defaultUserId={assignDefaultUserId}
        onSave={handleAssign}
        onClose={() => { if (!assignBusy) setAssignOpen(false); }}
      />

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
      {dialog?.kind === 'assignments' && (
        <AssignmentsDialog
          // The person's row from the latest list, so the dialog shows each change.
          user={users.find((user) => user.id === dialog.user.id) ?? dialog.user}
          onClose={() => setDialog(null)}
          onChanged={refresh}
          onChangeRole={(projectId, role) => updateProjectAssignment(dialog.user.id, projectId, role)}
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
