import { useCallback, useEffect, useState } from 'react';
import {
  assignToProject,
  createProgramUser,
  decideRegistration,
  fetchPendingRegistrations,
  fetchProgramOverview,
  fetchProgramUsers,
  removeProjectAssignment,
  updateProjectAssignment,
  updateProgramUserRole,
  updateProgramUserStatus,
} from '../../api/program';
import ProgramAddUserModal from '../../components/program/ProgramAddUserModal';
import ProgramAssignUserModal from '../../components/program/ProgramAssignUserModal';
import ProgramManageUserModal from '../../components/program/ProgramManageUserModal';
import ProgramPill from '../../components/program/ProgramPill';

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

const ACCOUNT_ROLES = [
  { slug: 'program-leader', label: 'Program Leader' },
  { slug: 'project-leader', label: 'Project Leader' },
  { slug: 'research-assistant', label: 'Research Assistant' },
  { slug: 'researcher', label: 'Researcher' },
  { slug: 'finance-admin', label: 'Finance/Admin Staff' },
];

export default function ProgramUsers() {
  const [pending, setPending] = useState([]);
  const [pendingRoleOptions, setPendingRoleOptions] = useState([]);
  const [pendingRoles, setPendingRoles] = useState({});
  const [pendingLoading, setPendingLoading] = useState(true);
  const [pendingError, setPendingError] = useState('');
  const [approvingId, setApprovingId] = useState(null);
  const [rejectingId, setRejectingId] = useState(null);
  const [rejectReasons, setRejectReasons] = useState({});
  const [rejectBusy, setRejectBusy] = useState(false);

  const [users, setUsers] = useState([]);
  const [usersLoading, setUsersLoading] = useState(true);
  const [teamProjectId, setTeamProjectId] = useState('');

  const [projects, setProjects] = useState([]);

  const [addOpen, setAddOpen] = useState(false);
  const [addBusy, setAddBusy] = useState(false);
  const [addError, setAddError] = useState('');
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignBusy, setAssignBusy] = useState(false);
  const [assignError, setAssignError] = useState('');
  const [assignDefaultUserId, setAssignDefaultUserId] = useState('');
  const [manageUser, setManageUser] = useState(null);
  const [manageAssignment, setManageAssignment] = useState(null);
  const [manageBusyKey, setManageBusyKey] = useState('');

  const [toast, setToast] = useState(null);

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
    setUsersLoading(true);
    try {
      const payload = await fetchProgramUsers(teamProjectId);
      setUsers(Array.isArray(payload?.users) ? payload.users : []);
    } catch (error) {
      setUsers([]);
      showToast(messageFor(error, 'Unable to load the team.'));
    } finally {
      setUsersLoading(false);
    }
  }, [teamProjectId]);

  useEffect(() => {
    loadPending();
  }, [loadPending]);

  useEffect(() => {
    loadUsers();
  }, [loadUsers]);

  useEffect(() => {
    let alive = true;
    fetchProgramOverview()
      .then((payload) => {
        if (alive) setProjects(Array.isArray(payload?.projects) ? payload.projects : []);
      })
      .catch(() => {
        if (alive) setProjects([]);
      });
    return () => { alive = false; };
  }, []);

  async function handleApprove(user) {
    setApprovingId(user.id);
    setPendingError('');
    try {
      await decideRegistration(user.id, {
        decision: 'approved',
        role: pendingRoles[user.id] || 'project-leader',
      });
      showToast(`${user.name} approved. You can now assign them to a project.`);
      await Promise.all([loadPending(), loadUsers()]);
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
      await loadUsers();
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
      await loadUsers();
    } catch (error) {
      setAssignError(messageFor(error, 'Unable to assign the user.'));
    } finally {
      setAssignBusy(false);
    }
  }

  async function handleUpdatePrimaryRole(userId, roleSlug, reason) {
    setManageBusyKey('primary');
    try {
      const response = await updateProgramUserRole(userId, { role: roleSlug, reason });
      const updated = response?.user;
      setManageUser((current) => (current ? {
        ...current,
        role: updated?.role ?? current.role,
        role_slug: updated?.role_slug ?? roleSlug,
        role_label: updated?.role_label ?? current.role_label,
      } : current));
      showToast('Primary role updated.');
      await loadUsers();
    } catch (error) {
      showToast(messageFor(error, 'Unable to update the role.'));
    } finally {
      setManageBusyKey('');
    }
  }

  async function handleUpdateAssignment(userId, projectId, roleSlug, reason) {
    setManageBusyKey('assignment');
    try {
      await updateProjectAssignment(userId, projectId, roleSlug, reason);
      setManageAssignment((current) => (current ? { ...current, role_slug: roleSlug } : current));
      showToast('Project role updated.');
      await loadUsers();
    } catch (error) {
      showToast(messageFor(error, 'Unable to update the project role.'));
    } finally {
      setManageBusyKey('');
    }
  }

  async function handleRemoveAssignment(userId, projectId, reason) {
    setManageBusyKey('assignment');
    try {
      await removeProjectAssignment(userId, projectId, reason);
      setManageAssignment(null);
      setManageUser((current) => (current ? { ...current, assignments: [] } : current));
      showToast('User removed from project.');
      await loadUsers();
    } catch (error) {
      showToast(messageFor(error, 'Unable to remove the assignment.'));
    } finally {
      setManageBusyKey('');
    }
  }

  async function handleToggleStatus(userId, reason) {
    if (!manageUser) return;
    const nextActive = !manageUser.is_active;
    setManageBusyKey('status');
    try {
      const response = await updateProgramUserStatus(userId, { is_active: nextActive, reason: reason || null });
      const updated = response?.user;
      setManageUser((current) => (current ? {
        ...current,
        is_active: updated?.is_active ?? nextActive,
        status: updated?.status ?? (nextActive ? 'Active' : 'Inactive'),
      } : current));
      showToast(nextActive ? 'Account reactivated.' : 'Account deactivated. Payroll entries are blocked.');
      await loadUsers();
    } catch (error) {
      showToast(messageFor(error, 'Unable to update status.'));
    } finally {
      setManageBusyKey('');
    }
  }

  const assignableProjects = projects.filter((project) => project.status === 'Ongoing' || project.status === 'Planning');

  const teamRows = [];
  users.forEach((user) => {
    if (!teamProjectId) {
      if (!user.assignments.length) {
        teamRows.push({ user, assignment: null });
      } else {
        user.assignments.forEach((assignment) => teamRows.push({ user, assignment }));
      }
    } else {
      const assignment = user.assignments.find(
        (item) => String(item.project_id) === String(teamProjectId)
      );
      if (assignment) teamRows.push({ user, assignment });
    }
  });

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Users and roles · Program Leader</div>
          <h1 className="program-h1">Users and roles</h1>
          <p className="program-sub">Roles are assigned per project. New registrations wait here for your review.</p>
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

      {(pendingLoading || pending.length > 0) && (
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

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Team</div>
            <div className="program-card-sub">One role per person per project</div>
          </div>
          <div className="program-field" style={{ minWidth: 220 }}>
            <label htmlFor="program-team-project">Project</label>
            <select id="program-team-project" className="program-select" value={teamProjectId} onChange={(event) => setTeamProjectId(event.target.value)}>
              <option value="">All projects</option>
              {projects.map((project) => (
                <option key={project.id} value={project.id}>{project.title} ({project.code})</option>
              ))}
            </select>
          </div>
        </div>

        {usersLoading ? (
          <div className="program-empty">Loading team…</div>
        ) : (
          <div className="program-scroll">
            <table className="program-table" style={{ minWidth: 900 }}>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Employee ID</th>
                  <th>Role</th>
                  <th>Project</th>
                  <th>Last login</th>
                  <th>Status</th>
                  <th className="r">Actions</th>
                </tr>
              </thead>
              <tbody>
                {teamRows.map(({ user, assignment }) => (
                  <tr key={`${user.id}-${assignment?.project_id ?? 'unassigned'}`}>
                    <td>
                      <div className="project-name">{user.name}</div>
                      <div className="project-code">{user.position || '—'}</div>
                    </td>
                    <td className="num">{user.employee_id || '—'}</td>
                    <td>{assignment?.role_label || user.role_label || '—'}</td>
                    <td>
                      {assignment
                        ? `${assignment.project} (${assignment.project_code || ''})`
                        : <span className="program-muted">Not assigned</span>}
                    </td>
                    <td className="num">{formatDate(user.last_login_at)}</td>
                    <td>
                      <ProgramPill value={user.is_active ? 'Active' : 'Inactive'} />
                      {!user.is_active && <div className="program-muted">Payroll blocked</div>}
                    </td>
                    <td className="r">
                      <button
                        type="button"
                        className="program-btn sm"
                        onClick={() => {
                          setManageUser(user);
                          setManageAssignment(assignment ?? null);
                        }}
                      >
                        Manage
                      </button>
                    </td>
                  </tr>
                ))}
                {teamRows.length === 0 && (
                  <tr>
                    <td colSpan={7} className="program-empty">No team members found for this project.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
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
        users={users}
        projects={assignableProjects}
        busy={assignBusy}
        error={assignError}
        defaultUserId={assignDefaultUserId}
        onSave={handleAssign}
        onClose={() => { if (!assignBusy) setAssignOpen(false); }}
      />
      {manageUser && (
        <ProgramManageUserModal
          user={manageUser}
          assignment={manageAssignment}
          allRoles={ACCOUNT_ROLES}
          busyKey={manageBusyKey}
          onUpdatePrimaryRole={handleUpdatePrimaryRole}
          onUpdateAssignment={handleUpdateAssignment}
          onRemoveAssignment={handleRemoveAssignment}
          onToggleStatus={handleToggleStatus}
          onRequestAssign={(userId) => {
            setManageUser(null);
            setManageAssignment(null);
            setAssignError('');
            setAssignDefaultUserId(String(userId));
            setAssignOpen(true);
          }}
          onClose={() => {
            setManageUser(null);
            setManageAssignment(null);
          }}
        />
      )}
    </>
  );
}
