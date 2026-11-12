import { useState } from 'react';
import { isValidMsuEmail } from '../../lib/authValidation';

const ROLES = [
  ['program-leader', 'Program Leader'],
  ['project-leader', 'Project Leader'],
  ['research-assistant', 'Research Assistant'],
  ['researcher', 'Researcher'],
  ['finance-admin', 'Finance/Admin Staff'],
];

export default function ProgramAddUserModal({ open, busy = false, error = '', onSave, onClose }) {
  const [form, setForm] = useState({
    firstName: '',
    lastName: '',
    email: '',
    empId: '',
    position: '',
    college: '',
    department: '',
    contactNumber: '',
    role: '',
  });
  const [localError, setLocalError] = useState('');

  if (!open) return null;

  function handleSubmit(event) {
    event.preventDefault();
    setLocalError('');

    if (!form.firstName || !form.lastName || !form.email || !form.empId || !form.position || !form.role) {
      setLocalError('First name, last name, email, employee ID, position and role are required.');
      return;
    }
    if (!isValidMsuEmail(form.email.trim())) {
      setLocalError('Email must be a valid MSU-Marawi address (@s.msumain.edu.ph or @msumain.edu.ph).');
      return;
    }
    if (!/^MSU-\d{5}$/i.test(form.empId.trim())) {
      setLocalError('Employee ID must be in format MSU-XXXXX (e.g., MSU-09999).');
      return;
    }
    if (!form.college || !form.department) {
      setLocalError('College and department are required.');
      return;
    }
    if (form.contactNumber && !/^09[0-9]{9}$/.test(form.contactNumber.trim())) {
      setLocalError('Contact number must be 11 digits starting with 09.');
      return;
    }

    onSave({
      first_name: form.firstName.trim(),
      last_name: form.lastName.trim(),
      email: form.email.trim().toLowerCase(),
      employee_id: form.empId.trim().toUpperCase(),
      position: form.position.trim(),
      college: form.college.trim(),
      department: form.department.trim(),
      contact_number: form.contactNumber.trim() || null,
      role: form.role,
    });
  }

  return (
    <div className="program-modal-overlay" onClick={busy ? undefined : onClose} role="dialog" aria-modal="true" aria-labelledby="program-add-user-title">
      <div className="program-modal wide" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="program-add-user-title">Add user</div>
            <p className="program-card-sub">The Program Leader creates the account; the user sets a password by email.</p>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close">×</button>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="program-form-grid">
            <div className="program-field">
              <label htmlFor="pu-first">First name</label>
              <input id="pu-first" className="program-input" value={form.firstName} disabled={busy} onChange={(event) => setForm({ ...form, firstName: event.target.value })} placeholder="Juan" autoComplete="given-name" />
            </div>
            <div className="program-field">
              <label htmlFor="pu-last">Last name</label>
              <input id="pu-last" className="program-input" value={form.lastName} disabled={busy} onChange={(event) => setForm({ ...form, lastName: event.target.value })} placeholder="Dela Cruz" autoComplete="family-name" />
            </div>
            <div className="program-field">
              <label htmlFor="pu-email">MSU email</label>
              <input id="pu-email" className="program-input" type="email" value={form.email} disabled={busy} onChange={(event) => setForm({ ...form, email: event.target.value })} placeholder="j.dela.cruz@s.msumain.edu.ph" autoComplete="email" />
            </div>
            <div className="program-field">
              <label htmlFor="pu-emp">Employee ID</label>
              <input id="pu-emp" className="program-input" value={form.empId} disabled={busy} onChange={(event) => setForm({ ...form, empId: event.target.value })} placeholder="MSU-09999" autoComplete="off" />
            </div>
            <div className="program-field">
              <label htmlFor="pu-position">Position</label>
              <input id="pu-position" className="program-input" value={form.position} disabled={busy} onChange={(event) => setForm({ ...form, position: event.target.value })} placeholder="Professor VI, CNSM" />
            </div>
            <div className="program-field">
              <label htmlFor="pu-role">Default role</label>
              <select id="pu-role" className="program-select" value={form.role} disabled={busy} onChange={(event) => setForm({ ...form, role: event.target.value })}>
                <option value="">Select a role</option>
                {ROLES.map(([slug, label]) => (
                  <option key={slug} value={slug}>{label}</option>
                ))}
              </select>
            </div>
            <div className="program-field">
              <label htmlFor="pu-college">College</label>
              <input id="pu-college" className="program-input" value={form.college} disabled={busy} onChange={(event) => setForm({ ...form, college: event.target.value })} placeholder="College of Natural Sciences and Mathematics" />
            </div>
            <div className="program-field">
              <label htmlFor="pu-department">Department</label>
              <input id="pu-department" className="program-input" value={form.department} disabled={busy} onChange={(event) => setForm({ ...form, department: event.target.value })} placeholder="Computer Science" />
            </div>
            <div className="program-field full">
              <label htmlFor="pu-contact">Contact number (optional)</label>
              <input id="pu-contact" className="program-input" value={form.contactNumber} disabled={busy} onChange={(event) => setForm({ ...form, contactNumber: event.target.value })} placeholder="09XXXXXXXXX" autoComplete="tel" />
            </div>
          </div>

          {(localError || error) && (
            <p className="program-error" role="alert" style={{ marginTop: 12 }}>{localError || error}</p>
          )}

          <div className="program-modal-actions">
            <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
            <button type="submit" className="program-btn primary" disabled={busy}>
              {busy ? 'Creating…' : 'Add user'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
