import { useEffect, useRef, useState } from 'react';
import { decideRegistration } from '../../api/program';
import { formatDateTime } from '../../lib/datetime';
import { ACCOUNT_ROLE_OPTIONS } from '../../lib/roles';
import {
  ONE_PROJECT_HELP,
  ONE_PROJECT_TRIMMED,
  canAddProjectRow,
  registrationProjectChoices,
  rowsForRole,
  singleProjectRole,
} from '../../lib/registrationAssignments';

let nextRowKey = 1;
const newRow = () => ({ key: nextRowKey++, project_id: '' });

function fieldErrorsFrom(error) {
  const errors = error?.response?.data?.errors ?? {};
  return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages?.[0]]));
}

/**
 * Review one pending registration: the applicant's details, then approve
 * with an account role and optional project assignments (saved together;
 * if one row is wrong nothing is saved and that row shows why), or reject
 * with a required reason. The applicant is emailed either way.
 */
export default function RegistrationReviewPanel({ user, projects, accountRoles, onClose, onDecided }) {
  const roleOptions = accountRoles?.length ? accountRoles : ACCOUNT_ROLE_OPTIONS;

  const [role, setRole] = useState('');
  const [rows, setRows] = useState([]);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});
  const [trimNote, setTrimNote] = useState('');
  const titleRef = useRef(null);

  const programWide = role === 'program-leader';
  const roleLabel = roleOptions.find((option) => option.slug === role)?.label ?? '';
  // A Project Leader leads one project: one slot, no "+ Add project", and
  // projects that already have a Project Leader are disabled.
  const singleProject = singleProjectRole(role);
  const projectChoices = registrationProjectChoices(projects, role);

  useEffect(() => { titleRef.current?.focus(); }, []);

  useEffect(() => {
    const onKey = (event) => { if (event.key === 'Escape' && !busy) onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [busy, onClose]);

  function updateRow(key, patch) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setFieldErrors({});
  }

  function addRow() {
    setRows((current) => [...current, newRow()]);
  }

  function changeRole(next) {
    const result = rowsForRole(rows, next, newRow, role);
    setRows(result.rows);
    setTrimNote(result.trimmed ? ONE_PROJECT_TRIMMED : '');
    setRole(next);
    setFieldErrors({});
  }

  async function submit(decision) {
    setError('');
    const local = {};
    if (decision === 'approved') {
      if (!role) local.role = 'Choose an account role.';
      if (!programWide) {
        rows.forEach((row, index) => {
          // The Project Leader's one slot may stay empty (no project yet).
          if (!row.project_id && !singleProject) local[`assignments.${index}.project_id`] = 'Choose a project.';
          const taken = projectChoices.find((project) => String(project.id) === String(row.project_id) && project.disabled);
          if (taken) local[`assignments.${index}.project_id`] = taken.reason;
        });
      }
    } else if (!reason.trim()) {
      local.remarks = 'A reason for rejection is required.';
    }
    if (Object.keys(local).length) {
      setFieldErrors(local);
      return;
    }

    setBusy(true);
    setFieldErrors({});
    try {
      const payload = decision === 'approved'
        ? {
          decision,
          role,
          // A Program Leader is program-wide: project rows are never sent.
          // The role on each project is the account role, so rows carry the project only.
          assignments: programWide ? [] : rows
            .filter((row) => row.project_id || !singleProject)
            .map((row) => ({ project_id: Number(row.project_id) })),
        }
        : { decision, remarks: reason.trim() };
      const data = await decideRegistration(user.id, payload);
      onDecided(data?.message || (decision === 'approved' ? `${user.name} approved.` : `${user.name}'s registration was rejected.`));
    } catch (failure) {
      const fields = fieldErrorsFrom(failure);
      setFieldErrors(fields);
      setError(Object.keys(fields).length
        ? 'Nothing was saved. Fix the highlighted field and try again.'
        : failure?.response?.data?.message || 'Could not save. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  const unit = [user.college, user.department].filter(Boolean).join(' · ') || '—';

  return (
    <div className="ld-panel-overlay" role="presentation" onClick={() => !busy && onClose()}>
      <aside
        className="ld-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rr-panel-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="ld-panel-head">
          <div>
            <div className="ld-panel-eyebrow">Pending registration</div>
            <h2 className="ld-panel-title" id="rr-panel-title" tabIndex={-1} ref={titleRef}>{user.name}</h2>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} disabled={busy} aria-label="Close panel">×</button>
        </div>

        <dl className="ld-fields">
          <div><dt>Email</dt><dd>{user.email}</dd></div>
          <div><dt>Employee ID</dt><dd>{user.employee_id || '—'}</dd></div>
          <div><dt>Position</dt><dd>{user.position || '—'}</dd></div>
          <div><dt>Contact number</dt><dd>{user.contact_number || '—'}</dd></div>
          <div style={{ gridColumn: '1 / -1' }}><dt>College/unit and department</dt><dd>{unit}</dd></div>
          <div><dt>Date submitted</dt><dd>{formatDateTime(user.created_at)}</dd></div>
        </dl>

        {error && <div className="program-banner error" role="alert">{error}</div>}

        {!rejecting && (
          <>
            <h3 className="ld-panel-sub">Account role</h3>
            <div className="program-field">
              <label htmlFor="rr-role">Account role (required)</label>
              <select
                id="rr-role"
                className="program-select"
                value={role}
                disabled={busy}
                aria-invalid={fieldErrors.role ? 'true' : undefined}
                aria-describedby={fieldErrors.role ? 'rr-role-error' : undefined}
                onChange={(event) => changeRole(event.target.value)}
              >
                <option value="">Choose a role</option>
                {roleOptions.map((option) => (
                  <option key={option.slug} value={option.slug}>{option.label}</option>
                ))}
              </select>
              {fieldErrors.role && <p className="program-error" id="rr-role-error"><span aria-hidden="true">⚠</span> {fieldErrors.role}</p>}
            </div>

            <h3 className="ld-panel-sub">Project assignments (optional)</h3>
            {!programWide && !singleProject && <p className="ld-muted">The person works on each project in their account role{roleLabel ? ` (${roleLabel})` : ''}.</p>}
            {singleProject && <p className="ld-muted">{ONE_PROJECT_HELP}</p>}
            {singleProject && trimNote && (
              <p className="ld-muted" role="status"><span aria-hidden="true">ℹ</span> {trimNote}</p>
            )}
            {programWide ? (
              <p className="ld-muted">
                <span aria-hidden="true">ℹ</span> A Program Leader oversees every project, so no project rows are needed.
                {rows.length > 0 && ' The rows you added will not be saved.'}
              </p>
            ) : (
              <>
                {fieldErrors.assignments && (
                  <p className="program-error" role="alert"><span aria-hidden="true">⚠</span> {fieldErrors.assignments}</p>
                )}
                {rows.length === 0 && (
                  <p className="ld-muted">No projects yet. You can add them now or later from Manage.</p>
                )}
                <ol className="rr-rows">
                  {rows.map((row, index) => {
                    const projectError = fieldErrors[`assignments.${index}.project_id`];
                    const roleError = fieldErrors[`assignments.${index}.role`];
                    const rowError = projectError || roleError;
                    return (
                      <li key={row.key} className={`rr-row${rowError ? ' has-error' : ''}`}>
                        <div className="program-field">
                          <label htmlFor={`rr-project-${row.key}`}>Project {index + 1}</label>
                          <select
                            id={`rr-project-${row.key}`}
                            className="program-select"
                            value={row.project_id}
                            disabled={busy}
                            aria-invalid={projectError ? 'true' : undefined}
                            onChange={(event) => updateRow(row.key, { project_id: event.target.value })}
                          >
                            <option value="">{singleProject ? 'No project yet' : 'Choose a project'}</option>
                            {projectChoices.map((project) => (
                              <option key={project.id} value={String(project.id)} disabled={project.disabled}>
                                {project.title}{project.code ? ` · ${project.code}` : ''}
                                {project.disabled ? ' — has a Project Leader' : ''}
                              </option>
                            ))}
                          </select>
                        </div>
                        <p className="pu-ro-value rr-row-role">
                          {roleLabel ? `as ${roleLabel}` : 'Role: choose the account role above'}
                        </p>
                        {!singleProject && <button
                          type="button"
                          className="program-btn"
                          disabled={busy}
                          aria-label={`Remove project ${index + 1}`}
                          onClick={() => { setRows((current) => current.filter((item) => item.key !== row.key)); setFieldErrors({}); }}
                        >
                          Remove
                        </button>}
                        {rowError && (
                          <p className="program-error rr-row-error" role="alert">
                            <span aria-hidden="true">⚠</span> Project {index + 1}: {rowError}
                          </p>
                        )}
                      </li>
                    );
                  })}
                </ol>
                {canAddProjectRow(role) && (
                  <div>
                    <button type="button" className="program-btn" disabled={busy || projects.length === 0} onClick={addRow}>
                      + Add project
                    </button>
                  </div>
                )}
              </>
            )}
          </>
        )}

        {rejecting && (
          <div className="program-field">
            <label htmlFor="rr-reason">Reason for rejection (required)</label>
            <textarea
              id="rr-reason"
              className="program-textarea"
              value={reason}
              disabled={busy}
              aria-invalid={fieldErrors.remarks ? 'true' : undefined}
              aria-describedby={fieldErrors.remarks ? 'rr-reason-error' : undefined}
              placeholder="Tell the applicant why this request was rejected. They will see it in the email."
              onChange={(event) => { setReason(event.target.value); setFieldErrors({}); }}
            />
            {fieldErrors.remarks && <p className="program-error" id="rr-reason-error"><span aria-hidden="true">⚠</span> {fieldErrors.remarks}</p>}
          </div>
        )}

        <div className="ld-panel-actions">
          {rejecting ? (
            <>
              <button type="button" className="program-btn" disabled={busy} onClick={() => { setRejecting(false); setError(''); setFieldErrors({}); }}>
                Cancel
              </button>
              <button type="button" className="program-btn primary" disabled={busy} onClick={() => submit('rejected')}>
                {busy ? 'Rejecting…' : 'Confirm rejection'}
              </button>
            </>
          ) : (
            <>
              <button type="button" className="program-btn" disabled={busy} onClick={() => { setRejecting(true); setError(''); setFieldErrors({}); }}>
                Reject
              </button>
              <button type="button" className="program-btn primary" disabled={busy} onClick={() => submit('approved')}>
                {busy ? 'Approving…' : 'Approve'}
              </button>
            </>
          )}
        </div>
      </aside>
    </div>
  );
}
