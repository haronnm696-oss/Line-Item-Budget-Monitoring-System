import { useState } from 'react';

const PROJECT_ROLES = [
  ['project-leader', 'Project Leader'],
  ['research-assistant', 'Research Assistant'],
  ['researcher', 'Researcher'],
  ['finance-admin', 'Finance/Admin Staff'],
];

const ASSIGNABLE_STATUSES = ['Ongoing', 'Planning'];

export default function ProgramAssignUserModal({ open, users = [], projects = [], busy = false, error = '', defaultUserId = '', onSave, onClose }) {
  const [form, setForm] = useState({ userId: defaultUserId ? String(defaultUserId) : '', projectId: '', role: '' });
  const [localError, setLocalError] = useState('');

  if (!open) return null;

  const activeUsers = users.filter((user) => user.is_active);
  const activeProjects = projects.filter((project) => ASSIGNABLE_STATUSES.includes(project.status));

  function handleSubmit(event) {
    event.preventDefault();
    setLocalError('');

    if (!form.userId || !form.projectId || !form.role) {
      setLocalError('User, project and role are all required.');
      return;
    }

    onSave({
      user_id: Number(form.userId),
      project_id: Number(form.projectId),
      role: form.role,
    });
  }

  return (
    <div className="program-modal-overlay" onClick={busy ? undefined : onClose} role="dialog" aria-modal="true" aria-labelledby="program-assign-title">
      <div className="program-modal" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="program-assign-title">Assign to project</div>
            <p className="program-card-sub">One role per person per project.</p>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close">×</button>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="program-field">
            <label htmlFor="assign-user">User</label>
            <select
              id="assign-user"
              className="program-select"
              value={form.userId}
              disabled={busy}
              onChange={(event) => setForm({ ...form, userId: event.target.value })}
            >
              <option value="">Select a user</option>
              {activeUsers.map((user) => (
                <option key={user.id} value={user.id}>{user.name} ({user.email})</option>
              ))}
            </select>
            {activeUsers.length === 0 && <div className="program-muted">No active users available.</div>}
          </div>

          <div className="program-field" style={{ marginTop: 14 }}>
            <label htmlFor="assign-project">Project</label>
            <select
              id="assign-project"
              className="program-select"
              value={form.projectId}
              disabled={busy}
              onChange={(event) => setForm({ ...form, projectId: event.target.value })}
            >
              <option value="">Select a project</option>
              {activeProjects.map((project) => (
                <option key={project.id} value={project.id}>{project.title} ({project.code})</option>
              ))}
            </select>
            {activeProjects.length === 0 && <div className="program-muted">No active projects available.</div>}
          </div>

          <div className="program-field" style={{ marginTop: 14 }}>
            <label htmlFor="assign-role">Role on this project</label>
            <select
              id="assign-role"
              className="program-select"
              value={form.role}
              disabled={busy}
              onChange={(event) => setForm({ ...form, role: event.target.value })}
            >
              <option value="">Select a role</option>
              {PROJECT_ROLES.map(([slug, label]) => (
                <option key={slug} value={slug}>{label}</option>
              ))}
            </select>
          </div>

          {(localError || error) && (
            <p className="program-error" role="alert" style={{ marginTop: 12 }}>{localError || error}</p>
          )}

          <div className="program-modal-actions">
            <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
            <button type="submit" className="program-btn primary" disabled={busy}>
              {busy ? 'Assigning…' : 'Assign'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
