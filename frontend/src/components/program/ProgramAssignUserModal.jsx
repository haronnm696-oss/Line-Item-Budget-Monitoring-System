import { useReducer } from 'react';
import { NO_PROJECTS_LEFT, assignFormReducer, assignRoleText, assignSubmission, initialAssignForm, projectChoices } from '../../lib/assignForm';

/**
 * Assign to project: user and project only. The role on the project is the
 * person's account role, shown read-only. Mount it only while open, so each
 * open starts clean (see lib/assignForm.js). `defaultProjectId` preselects the
 * project (Team > Add member on a project page).
 */
export default function ProgramAssignUserModal({ users = [], projects = [], busy = false, error = '', defaultUserId = '', defaultProjectId = '', onSave, onClose }) {
  const [form, dispatch] = useReducer(assignFormReducer, null, () => initialAssignForm(defaultUserId, defaultProjectId));

  // Program Leaders are program-wide and never listed (the server leaves them out too).
  const activeUsers = users.filter((user) => user.is_active && user.role_slug !== 'program-leader');
  const selectedUser = activeUsers.find((user) => String(user.id) === form.userId) ?? null;
  // Leaves out projects the person is already on; greys out, with the reason,
  // projects that already have a leader when the person is a Project Leader.
  const choices = projectChoices(projects, selectedUser);
  const disabledChoices = choices.filter((project) => project.disabled);
  const projectValue = choices.some((project) => !project.disabled && String(project.id) === form.projectId) ? form.projectId : '';
  const roleText = assignRoleText(form, activeUsers);
  const shownError = form.error || error;

  function handleSubmit(event) {
    event.preventDefault();
    const result = assignSubmission(form, activeUsers, projects);
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
            {choices.length === 0 ? (
              <p className="program-muted" id="assign-project-none" role="status">
                {selectedUser ? NO_PROJECTS_LEFT : 'No active projects available.'}
              </p>
            ) : (
              <>
                <select
                  id="assign-project"
                  name="project_id"
                  className="program-select"
                  value={projectValue}
                  disabled={busy}
                  aria-describedby={disabledChoices.length ? 'assign-project-taken' : undefined}
                  onChange={change('projectId')}
                >
                  <option value="">Select a project</option>
                  {choices.map((project) => (
                    <option key={project.id} value={String(project.id)} disabled={project.disabled}>
                      {project.title} ({project.code}){project.disabled ? ` — led by ${project.leader.name}` : ''}
                    </option>
                  ))}
                </select>
                {disabledChoices.length > 0 && (
                  <ul className="program-muted" id="assign-project-taken">
                    {disabledChoices.map((project) => (
                      <li key={project.id}><span aria-hidden="true">⊘ </span>{project.code}: {project.reason}</li>
                    ))}
                  </ul>
                )}
              </>
            )}
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
