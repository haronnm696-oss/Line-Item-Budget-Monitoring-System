import { useState } from 'react';
import { peso } from './money';

const FUNDING_AGENCIES = ['MOST-BARMM', 'DOST-PCAARRD', 'CHED', 'DOST-PCHRD', 'DOST', 'USAID', 'World Bank'];
const PROJECT_LEADERS = [
  'Prof. Mudzna M. Asakil',
  'Dr. Aminah Macarambon',
  'Dr. S. Mangondaya',
  'Dr. N. Guro',
  'Dr. F. Alonto',
];

export default function NewProjectModal({ open, onSave, onClose }) {
  const [form, setForm] = useState({
    code: '',
    title: '',
    agency: '',
    counterpart: '',
    funding: '',
    period: '',
    leader: '',
  });
  const [error, setError] = useState('');

  const total = (parseFloat(form.counterpart || '0') || 0) + (parseFloat(form.funding || '0') || 0);

  function handleSubmit(e) {
    e.preventDefault();
    setError('');
    if (!form.code || !form.title || !form.agency || !form.counterpart || !form.funding || !form.period || !form.leader) {
      setError('All fields are required.');
      return;
    }
    const parsedCounterpart = parseFloat(form.counterpart);
    const parsedFunding = parseFloat(form.funding);
    if (Number.isNaN(parsedCounterpart) || Number.isNaN(parsedFunding) || parsedCounterpart < 0 || parsedFunding < 0) {
      setError('Budget amounts must be valid numbers.');
      return;
    }
    onSave({
      id: Date.now(),
      code: form.code.trim(),
      title: form.title.trim(),
      agency: form.agency,
      implementing_agency: 'MSU-Marawi',
      project_leader: form.leader,
      program: form.leader ? `${form.leader} Program` : '',
      budget: parsedCounterpart + parsedFunding,
      spent: 0,
      counterpart: parsedCounterpart,
      funding: parsedFunding,
      period: form.period.trim(),
      status: 'Planning',
    });
    onClose();
  }

  return (
    <>
      {open && (
        <div className="modal-overlay" onClick={onClose} role="dialog" aria-modal="true" aria-labelledby="new-project-title">
          <div className="modal-panel" onClick={(e) => e.stopPropagation()}>
            <div className="modal-head">
              <h3 id="new-project-title">New Project</h3>
              <button type="button" className="modal-close" onClick={onClose} aria-label="Close">
                ×
              </button>
            </div>
            <form onSubmit={handleSubmit} noValidate style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 14, padding: '16px 20px 20px' }}>
              {error && (
                <div className="banner over" style={{ gridColumn: '1 / -1' }}>{error}</div>
              )}
              <div className="field">
                <label>Project Code</label>
                <input
                  name="code"
                  type="text"
                  autoComplete="off"
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value })}
                  placeholder="e.g. MOST-RD-2026-002"
                />
              </div>
              <div className="field">
                <label>Project Title</label>
                <input
                  name="title"
                  type="text"
                  autoComplete="off"
                  value={form.title}
                  onChange={(e) => setForm({ ...form, title: e.target.value })}
                  placeholder="e.g. Enhancing Urban Mobility"
                />
              </div>
              <div className="field">
                <label>Funding Agency</label>
                <select
                  name="agency"
                  value={form.agency}
                  onChange={(e) => setForm({ ...form, agency: e.target.value })}
                >
                  <option value="">Select agency</option>
                  {FUNDING_AGENCIES.map((a) => (
                    <option key={a} value={a}>{a}</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ gridColumn: '1 / -1' }}>
                <label>Approved Budget (split by source)</label>
                <div style={{ display: 'flex', gap: 10 }}>
                  <div style={{ flex: 1 }}>
                    <label style={{ fontSize: '11px', color: 'var(--muted)', display: 'block', marginBottom: '4px' }}>Counterpart (Implementing Agency)</label>
                    <input
                      name="counterpart"
                      type="number"
                      step="0.01"
                      min="0"
                      value={form.counterpart}
                      onChange={(e) => setForm({ ...form, counterpart: e.target.value })}
                      placeholder="0.00"
                    />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label style={{ fontSize: '11px', color: 'var(--muted)', display: 'block', marginBottom: '4px' }}>Funding Agency</label>
                    <input
                      name="funding"
                      type="number"
                      step="0.01"
                      min="0"
                      value={form.funding}
                      onChange={(e) => setForm({ ...form, funding: e.target.value })}
                      placeholder="0.00"
                    />
                  </div>
                </div>
                <div style={{ fontSize: '11px', color: 'var(--muted)', marginTop: '4px' }}>Total: {peso(total)}</div>
              </div>
              <div className="field">
                <label>Project Duration / Period</label>
                <input
                  name="period"
                  type="text"
                  autoComplete="off"
                  value={form.period}
                  onChange={(e) => setForm({ ...form, period: e.target.value })}
                  placeholder="e.g. Jan – Dec 2026"
                />
              </div>
              <div className="field">
                <label>Assigned Project Leader</label>
                <select
                  name="leader"
                  value={form.leader}
                  onChange={(e) => setForm({ ...form, leader: e.target.value })}
                >
                  <option value="">Select leader</option>
                  {PROJECT_LEADERS.map((l) => (
                    <option key={l} value={l}>{l}</option>
                  ))}
                </select>
              </div>
              <div style={{ gridColumn: '1 / -1', display: 'flex', justifyContent: 'flex-end', gap: 8, paddingTop: 8 }}>
                <button type="button" className="btn" onClick={onClose}>
                  Cancel
                </button>
                <button type="submit" className="btn primary">
                  Create Project
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
