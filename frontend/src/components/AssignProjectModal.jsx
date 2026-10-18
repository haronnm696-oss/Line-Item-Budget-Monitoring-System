import { useState } from 'react';

const PROJECT_ROLES = [
  'Project Leader',
  'Research Assistant',
  'Researcher',
  'Finance/Admin Staff',
];

export default function AssignProjectModal({ open, users, projects, onSave, onClose }) {
  const [form, setForm] = useState({
    userId: '',
    projectId: '',
    role: '',
    assignedDate: '',
  });
  const [error, setError] = useState('');

  const activeProjects = projects.filter((p) => p.status === 'Ongoing' || p.status === 'Planning');
  const availableUsers = users.filter((u) => u.status === 'Active');

  function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (!form.userId || !form.projectId || !form.role || !form.assignedDate) {
      setError('All fields are required.');
      return;
    }

    onSave({
      userId: parseInt(form.userId, 10),
      projectId: parseInt(form.projectId, 10),
      role: form.role,
      assigned: form.assignedDate,
    });
    onClose();
  }

  return (
    <>
      {open && (
        <div className="modal-overlay" onClick={onClose} role="dialog" aria-modal="true" aria-labelledby="assign-project-title">
          <div className="modal-panel" onClick={(e) => e.stopPropagation()}>
            <div className="modal-head">
              <h3 id="assign-project-title">Assign User to Project</h3>
              <button type="button" className="modal-close" onClick={onClose} aria-label="Close">
                ×
              </button>
            </div>
            <form onSubmit={handleSubmit} noValidate style={{ padding: '16px 20px 20px', display: 'grid', gap: 14 }}>
              {error && (
                <div className="banner over" style={{ gridColumn: '1 / -1' }}>{error}</div>
              )}
              <div className="field">
                <label>Select User</label>
                <select
                  name="userId"
                  value={form.userId}
                  onChange={(e) => setForm({ ...form, userId: e.target.value })}
                >
                  <option value="">Select a user</option>
                  {availableUsers.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name} ({u.email})
                    </option>
                  ))}
                </select>
                {availableUsers.length === 0 && (
                  <small style={{ color: 'var(--muted)', fontSize: '11px', display: 'block', marginTop: '4px' }}>
                    No active users available.
                  </small>
                )}
              </div>
              <div className="field">
                <label>Select Project</label>
                <select
                  name="projectId"
                  value={form.projectId}
                  onChange={(e) => setForm({ ...form, projectId: e.target.value })}
                >
                  <option value="">Select a project</option>
                  {activeProjects.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.title} ({p.code})
                    </option>
                  ))}
                </select>
                {activeProjects.length === 0 && (
                  <small style={{ color: 'var(--muted)', fontSize: '11px', display: 'block', marginTop: '4px' }}>
                    No active projects available.
                  </small>
                )}
              </div>
              <div className="field">
                <label>Role on this Project</label>
                <select
                  name="role"
                  value={form.role}
                  onChange={(e) => setForm({ ...form, role: e.target.value })}
                >
                  <option value="">Select a role</option>
                  {PROJECT_ROLES.map((r) => (
                    <option key={r} value={r}>{r}</option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label>Assignment Date</label>
                <input
                  name="assignedDate"
                  type="date"
                  value={form.assignedDate}
                  onChange={(e) => setForm({ ...form, assignedDate: e.target.value })}
                />
              </div>
              <div style={{ gridColumn: '1 / -1', display: 'flex', justifyContent: 'flex-end', gap: 8, paddingTop: 8 }}>
                <button type="button" className="btn" onClick={onClose}>
                  Cancel
                </button>
                <button type="submit" className="btn primary">
                  Assign
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
