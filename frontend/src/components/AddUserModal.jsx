import { useState } from 'react';

const DEFAULT_ROLES = [
  'Program Leader',
  'Project Leader',
  'Research Assistant',
  'Researcher',
  'Finance/Admin Staff',
];

export default function AddUserModal({ open, onSave, onClose }) {
  const [form, setForm] = useState({
    name: '',
    email: '',
    empId: '',
    position: '',
    role: '',
  });
  const [error, setError] = useState('');

  function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (!form.name || !form.email || !form.empId || !form.position || !form.role) {
      setError('All fields are required.');
      return;
    }

    const emailLower = form.email.trim().toLowerCase();
    if (!/^[^\s@]+@msumain\.edu\.ph$/.test(emailLower)) {
      setError('Email must be a valid MSU-Marawi address (@msumain.edu.ph).');
      return;
    }

    if (!/^MSU-\d{5}$/.test(form.empId.trim())) {
      setError('Employee ID must be in format MSU-XXXXX (e.g., MSU-09999).');
      return;
    }

    onSave({
      id: Date.now(),
      name: form.name.trim(),
      email: emailLower,
      empId: form.empId.trim().toUpperCase(),
      position: form.position.trim(),
      role: form.role,
      status: 'Active',
      joined: new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }),
    });
    onClose();
  }

  return (
    <>
      {open && (
        <div className="modal-overlay" onClick={onClose} role="dialog" aria-modal="true" aria-labelledby="add-user-title">
          <div className="modal-panel" onClick={(e) => e.stopPropagation()}>
            <div className="modal-head">
              <h3 id="add-user-title">Add User</h3>
              <button type="button" className="modal-close" onClick={onClose} aria-label="Close">
                ×
              </button>
            </div>
            <form onSubmit={handleSubmit} noValidate style={{ padding: '16px 20px 20px', display: 'grid', gap: 14 }}>
              {error && (
                <div className="banner over" style={{ gridColumn: '1 / -1' }}>{error}</div>
              )}
              <div className="field">
                <label>Full Name</label>
                <input
                  name="name"
                  type="text"
                  autoComplete="name"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="e.g. Juan Dela Cruz"
                />
              </div>
              <div className="field">
                <label>MSU Email</label>
                <input
                  name="email"
                  type="email"
                  autoComplete="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  placeholder="e.g. j.dela.cruz@msumain.edu.ph"
                />
                <small style={{ color: 'var(--muted)', fontSize: '11px', display: 'block', marginTop: '4px' }}>
                  Must be an @msumain.edu.ph address
                </small>
              </div>
              <div className="field">
                <label>Employee ID</label>
                <input
                  name="empId"
                  type="text"
                  autoComplete="off"
                  value={form.empId}
                  onChange={(e) => setForm({ ...form, empId: e.target.value })}
                  placeholder="e.g. MSU-09999"
                />
                <small style={{ color: 'var(--muted)', fontSize: '11px', display: 'block', marginTop: '4px' }}>
                  Format: MSU-XXXXX
                </small>
              </div>
              <div className="field">
                <label>Position / Department</label>
                <input
                  name="position"
                  type="text"
                  autoComplete="off"
                  value={form.position}
                  onChange={(e) => setForm({ ...form, position: e.target.value })}
                  placeholder="e.g. Professor VI, CNSM"
                />
              </div>
              <div className="field">
                <label>Default Role</label>
                <select
                  name="role"
                  value={form.role}
                  onChange={(e) => setForm({ ...form, role: e.target.value })}
                >
                  <option value="">Select a role</option>
                  {DEFAULT_ROLES.map((r) => (
                    <option key={r} value={r}>{r}</option>
                  ))}
                </select>
              </div>
              <div style={{ gridColumn: '1 / -1', display: 'flex', justifyContent: 'flex-end', gap: 8, paddingTop: 8 }}>
                <button type="button" className="btn" onClick={onClose}>
                  Cancel
                </button>
                <button type="submit" className="btn primary">
                  Add User
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
