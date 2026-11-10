import { useState } from 'react';

const PROJECT_ROLES = [
  ['project-leader', 'Project Leader'],
  ['research-assistant', 'Research Assistant'],
  ['researcher', 'Researcher'],
  ['finance-admin', 'Finance/Admin Staff'],
];

export default function ProgramManageUserModal({
  user,
  assignment,
  allRoles,
  busyKey,
  onUpdatePrimaryRole,
  onUpdateAssignment,
  onRemoveAssignment,
  onToggleStatus,
  onRequestAssign,
  onClose,
}) {
  const [primaryRole, setPrimaryRole] = useState(user?.primaryRole?.slug || user?.role_slug || '');
  const [projectRole, setProjectRole] = useState(assignment?.role_slug || assignment?.role || '');
  const [reason, setReason] = useState('');

  if (!user) return null;

  return (
    <div className="program-modal-overlay" onClick={busyKey ? undefined : onClose} role="dialog" aria-modal="true" aria-labelledby="program-manage-title">
      <div className="program-modal" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="program-manage-title">
              Manage {user.name}
            </div>
            <p className="program-card-sub">
              {user.email} · {user.position || '—'}
            </p>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span className={`program-pill ${user.is_active ? 'healthy' : 'neutral'}`}>
              {user.is_active ? 'Active' : 'Inactive'}
              <span aria-hidden="true">{user.is_active ? '✓' : '⊘'}</span>
            </span>
            <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={Boolean(busyKey)}>×</button>
          </div>
        </div>

        {!user.is_active && (
          <div className="program-banner error" role="status" style={{ marginBottom: 14 }}>
            This account is deactivated. Sign-in, new assignments and new payroll entries are blocked; history is kept.
          </div>
        )}

        <div className="program-field">
          <label htmlFor="pm-primary-role">Primary role</label>
          <select
            id="pm-primary-role"
            className="program-select"
            value={primaryRole}
            disabled={busyKey === 'primary'}
            onChange={(event) => setPrimaryRole(event.target.value)}
          >
            <option value="">No role</option>
            {allRoles.map((role) => (
              <option key={role.slug} value={role.slug}>{role.label}</option>
            ))}
          </select>
        </div>

        {assignment ? (
          <div className="program-field" style={{ marginTop: 14 }}>
            <label htmlFor="pm-project-role">Project role</label>
            <select
              id="pm-project-role"
              className="program-select"
              value={projectRole}
              disabled={busyKey === 'assignment'}
              onChange={(event) => setProjectRole(event.target.value)}
            >
              <option value="">Select a role</option>
              {PROJECT_ROLES.map(([slug, label]) => (
                <option key={slug} value={slug}>{label}</option>
              ))}
            </select>
          </div>
        ) : (
          <div className="program-reject" style={{ marginTop: 14 }}>
            <label>Project assignment</label>
            <div className="program-row-sub">This user is not assigned to a project yet.</div>
            <div className="program-reject-actions" style={{ paddingTop: 0 }}>
              <button type="button" className="program-btn" onClick={() => onRequestAssign?.(user.id)}>
                Assign to project
              </button>
            </div>
          </div>
        )}

        {assignment ? (
          <div className="program-reject" style={{ marginTop: 14 }}>
            <label>Assignment</label>
            <div className="program-reject-actions" style={{ paddingTop: 0 }}>
              <button
                type="button"
                className="program-btn primary"
                disabled={busyKey === 'assignment'}
                onClick={() => assignment?.project_id && projectRole && onUpdateAssignment?.(user.id, assignment.project_id, projectRole, reason || null)}
              >
                Save project role
              </button>
              <button
                type="button"
                className="program-btn danger"
                disabled={busyKey === 'assignment'}
                onClick={() => assignment?.project_id && onRemoveAssignment?.(user.id, assignment.project_id, reason || null)}
              >
                Remove from project
              </button>
            </div>
          </div>
        ) : null}

        <div className="program-reject" style={{ marginTop: 14 }}>
          <label htmlFor="pm-reason">Reason (required for deactivation)</label>
          <textarea id="pm-reason" className="program-textarea" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Why is this change being made?" />
        </div>

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={Boolean(busyKey)}>Close</button>
          <button type="button" className="program-btn primary" disabled={busyKey === 'primary'} onClick={() => primaryRole && onUpdatePrimaryRole?.(user.id, primaryRole, reason || null)}>
            Save primary role
          </button>
          <button
            type="button"
            className={`program-btn${user.is_active ? ' danger' : ' primary'}`}
            disabled={busyKey === 'status'}
            onClick={() => {
              if (user.is_active && !reason.trim()) {
                const proceed = window.confirm('No reason was entered. Deactivate this account without a reason?');
                if (!proceed) return;
              }
              onToggleStatus?.(user.id, reason || null);
            }}
          >
            {busyKey === 'status' ? 'Saving…' : (user.is_active ? 'Deactivate account' : 'Reactivate account')}
          </button>
        </div>
      </div>
    </div>
  );
}
