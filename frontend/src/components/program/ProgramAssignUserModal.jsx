import { useReducer } from 'react';
import { assignFormReducer, assignRoleText, assignSubmission, initialAssignForm } from '../../lib/assignForm';

const ASSIGNABLE_STATUSES = ['Ongoing', 'Planning'];

/**
 * Assign to project: user and project only. The role on the project is the
 * person's account role, shown read-only. Mount it only while open, so each
 * open starts clean (see lib/assignForm.js).
 */
export default function ProgramAssignUserModal({ users = [], projects = [], busy = false, error = '', defaultUserId = '', onSave, onClose }) {
  const [form, dispatch] = useReducer(assignFormReducer, defaultUserId, initialAssignForm);

  // Program Leaders are program-wide and never listed (the server leaves them out too).
  const activeUsers = users.filter((user) => user.is_active && user.role_slug !== 'program-leader');
  const activeProjects = projects.filter((project) => ASSIGNABLE_STATUSES.includes(project.status));
  const roleText = assignRoleText(form, activeUsers);
  const shownError = form.error || error;

  function handleSubmit(event) {
    event.preventDefault();
    const result = assignSubmission(form, activeUsers);
    if (result.error) {
      dispatch({ type: 'invalid', error: result.error });
      return;
    }
    onSave(result.payload);
  }

  const change = (field) => (event) => dispatch({ type: 'change', field, value: event.target.value });

  return (
    <div className="program-modal-overlay" onClick={busy ? undefined : onClose} role="dialog" aria-modal="true" aria-labelledby="program-assign-title">
      <div className="program-modal" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="program-assign-title">Assign to project</div>
            <p className="program-card-sub">The person works on the project in their account role.</p>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close">×</button>
        </div>

        <form onSubmit={handleSubmit} noValidate>
          <div className="program-field">
            <label htmlFor="assign-user">User</label>
            <select id="assign-user" name="user_id" className="program-select" value={form.userId} disabled={busy} onChange={change('userId')}>
              <option value="">Select a user</option>
              {activeUsers.map((user) => (
                <option key={user.id} value={String(user.id)}>{user.name} ({user.email})</option>
              ))}
            </select>
            {activeUsers.length === 0 && <div className="program-muted">No active users available.</div>}
            <div className="program-muted">Program Leaders oversee every project and are not assigned to one.</div>
          </div>

          <div className="program-field" style={{ marginTop: 14 }}>
            <label htmlFor="assign-project">Project</label>
            <select id="assign-project" name="project_id" className="program-select" value={form.projectId} disabled={busy} onChange={change('projectId')}>
              <option value="">Select a project</option>
              {activeProjects.map((project) => (
                <option key={project.id} value={String(project.id)}>{project.title} ({project.code})</option>
              ))}
            </select>
            {activeProjects.length === 0 && <div className="program-muted">No active projects available.</div>}
          </div>

          <div className="program-field" style={{ marginTop: 14 }}>
            <span className="pu-ro-label">Role on this project (their account role)</span>
            <p className="pu-ro-value" aria-live="polite">
              {roleText || 'Choose a user to see their role'}
            </p>
          </div>

          {shownError && (
            <p className="program-error" role="alert" style={{ marginTop: 12 }}>{shownError}</p>
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
