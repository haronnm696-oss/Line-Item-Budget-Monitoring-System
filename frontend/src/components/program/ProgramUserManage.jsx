import { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ACCOUNT_ROLE_OPTIONS, ALL_ACCOUNT_ROLE_OPTIONS } from '../../lib/roles';
import { contactNumberCounter, contactNumberError, isEmailFormat } from '../../lib/authValidation';
import ContactNumberInput from '../ContactNumberInput';
import AcademicUnitFields from '../AcademicUnitFields';
import { academicUnitErrors, academicUnitPayload, unitFormFromUser } from '../../lib/academicUnits';
import { useAcademicUnits } from '../../lib/useAcademicUnits';

const MSU_EMPLOYEE_ID = /^MSU-\d{5}$/i;

/**
 * "Manage" menu for one person: a real menu button (aria-haspopup="menu",
 * aria-expanded). Escape or a click outside closes it and focus returns to
 * the button. Arrow keys move between items.
 */
export function ManageMenu({ user, onEdit, onRole, onAssignments, onStatus }) {
  const [open, setOpen] = useState(false);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const menuId = useId();

  function close(returnFocus = true) {
    setOpen(false);
    if (returnFocus) buttonRef.current?.focus();
  }

  useEffect(() => {
    if (!open) return undefined;
    const first = menuRef.current?.querySelector('[role="menuitem"]:not([aria-disabled="true"])');
    first?.focus();

    function onPointer(event) {
      if (!menuRef.current?.contains(event.target) && !buttonRef.current?.contains(event.target)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onPointer);
    return () => document.removeEventListener('mousedown', onPointer);
  }, [open]);

  function onMenuKeyDown(event) {
    const items = [...(menuRef.current?.querySelectorAll('[role="menuitem"]') ?? [])];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      items[(index + step + items.length) % items.length]?.focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      (event.key === 'Home' ? items[0] : items[items.length - 1])?.focus();
    } else if (event.key === 'Tab') {
      setOpen(false);
    }
  }

  function choose(action) {
    setOpen(false);
    action();
  }

  const programWide = user.program_wide;
  const canAssign = user.is_active && !programWide;
  const hasAssignments = (user.assignments ?? []).length > 0;
  // One entry for adding and removing projects: open when there is something to remove or the person can be added.
  const canEditAssignments = hasAssignments || canAssign;
  const assignmentsHint = canEditAssignments
    ? undefined
    : (programWide ? 'Program Leaders already oversee all projects' : 'Reactivate the account first');
  // One Program Leader: the last active one keeps the account and role
  // (hand it over with "Transfer Program Leader role"); nobody deactivates
  // themselves; an inactive Program Leader stays inactive while another is active.
  const lastLeader = Boolean(user.is_last_program_leader);
  const roleHint = lastLeader ? 'The only active Program Leader keeps this role. Use "Transfer Program Leader role".' : undefined;
  const canDeactivate = !user.is_self && !lastLeader;
  const deactivateHint = user.is_self
    ? 'You cannot deactivate your own account'
    : (lastLeader ? 'The only active Program Leader cannot be deactivated' : undefined);
  const canReactivate = user.can_reactivate !== false;
  const reactivateHint = canReactivate ? undefined : 'Another Program Leader is active; the system has one Program Leader';

  return (
    <div className="pu-menu-wrap">
      <button
        ref={buttonRef}
        type="button"
        className="program-btn sm"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((value) => !value)}
      >
        Manage <span aria-hidden="true">▾</span>
        <span className="pa-sr-only"> {user.name}</span>
      </button>
      {open && (
        <div ref={menuRef} id={menuId} role="menu" aria-label={`Manage ${user.name}`} className="pu-menu" onKeyDown={onMenuKeyDown}>
          <button type="button" role="menuitem" className="pu-menu-item" onClick={() => choose(onEdit)}>
            Edit details
          </button>
          <button
            type="button"
            role="menuitem"
            className="pu-menu-item"
            aria-disabled={lastLeader || undefined}
            title={roleHint}
            onClick={() => !lastLeader && choose(onRole)}
          >
            Change account role
          </button>
          <button
            type="button"
            role="menuitem"
            className="pu-menu-item"
            aria-disabled={!canEditAssignments}
            title={assignmentsHint}
            onClick={() => canEditAssignments && choose(onAssignments)}
          >
            Edit project assignments
          </button>
          <Link role="menuitem" className="pu-menu-item" to={`/program-audit?q=${encodeURIComponent(user.name)}`} onClick={() => setOpen(false)}>
            View in audit log
          </Link>
          <div className="pu-menu-sep" role="separator" />
          {user.is_active ? (
            <button
              type="button"
              role="menuitem"
              className="pu-menu-item danger"
              aria-disabled={!canDeactivate || undefined}
              title={deactivateHint}
              onClick={() => canDeactivate && choose(onStatus)}
            >
              Deactivate account
            </button>
          ) : (
            <button
              type="button"
              role="menuitem"
              className="pu-menu-item"
              aria-disabled={!canReactivate || undefined}
              title={reactivateHint}
              onClick={() => canReactivate && choose(onStatus)}
            >
              Reactivate account
            </button>
          )}
        </div>
      )}
    </div>
  );
}

/** Shared dialog frame: Escape closes, focus moves into it, overlay click closes. */
function Dialog({ title, labelId, busy, onClose, children }) {
  const panelRef = useRef(null);

  useEffect(() => {
    const first = panelRef.current?.querySelector('input, select, textarea, button:not(.program-icon-btn)');
    first?.focus();
  }, []);

  return (
    <div
      className="program-modal-overlay"
      onClick={busy ? undefined : onClose}
      onKeyDown={(event) => { if (event.key === 'Escape' && !busy) onClose(); }}
      role="dialog"
      aria-modal="true"
      aria-labelledby={labelId}
    >
      <div ref={panelRef} className="program-modal" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div className="program-modal-title" id={labelId}>{title}</div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>
        {children}
      </div>
    </div>
  );
}

function FieldError({ id, message }) {
  if (!message) return null;
  return <p className="program-error" id={id}>{message}</p>;
}

export function EditDetailsDialog({ user, busy, error, fieldErrors = {}, onSave, onClose }) {
  const [form, setForm] = useState({
    name: user.name ?? '',
    position: user.position ?? '',
    email: user.email ?? '',
    employee_id: user.employee_id ?? '',
    contact_number: user.contact_number ?? '',
  });
  const [local, setLocal] = useState({});
  const errors = { ...fieldErrors, ...local };
  // College / unit: starts from the saved values (shown as they are, even if
  // not in the list); a changed pair must come from the list.
  const units = useAcademicUnits();
  const [unitEdit, setUnitEdit] = useState(null);
  const unitForm = unitEdit ?? (units.status === 'ready' ? unitFormFromUser(user, units.data) : null);

  function submit(event) {
    event.preventDefault();
    const next = {};
    const email = form.email.trim().toLowerCase();
    const employeeId = form.employee_id.trim().toUpperCase();
    // Existing (legacy) values are accepted as they are; the format rules
    // apply only when a value is changed. The server checks the same.
    const emailChanged = email !== String(user.email ?? '').trim().toLowerCase();
    const idChanged = employeeId !== String(user.employee_id ?? '').trim().toUpperCase();
    if (!form.name.trim()) next.name = 'Name is required.';
    if (!email) next.email = 'Email is required.';
    else if (emailChanged && !isEmailFormat(email)) next.email = 'Enter a valid email address.';
    if (idChanged && !MSU_EMPLOYEE_ID.test(employeeId)) next.employee_id = 'A new employee ID must use the format MSU-00000.';
    // Optional; an unchanged legacy number is kept, a new or changed one must match.
    const contactError = contactNumberError(form.contact_number, { original: user.contact_number ?? '' });
    if (contactError) next.contact_number = contactError;
    if (unitForm) Object.assign(next, academicUnitErrors(unitForm, units.data, { required: false, original: user }));
    setLocal(next);
    if (Object.keys(next).length) return;
    onSave({
      name: form.name.trim(),
      position: form.position.trim() || null,
      email: form.email.trim(),
      employee_id: form.employee_id.trim() || null,
      contact_number: form.contact_number || null,
      // Sent only once the list has loaded; otherwise the saved values are left alone.
      ...(unitForm ? academicUnitPayload(unitForm, units.data) : {}),
    });
  }

  const field = (key, label, type = 'text', hint = null, placeholder = undefined) => (
    <div className="program-field">
      <label htmlFor={`pu-edit-${key}`}>{label}</label>
      <input
        id={`pu-edit-${key}`}
        className="program-input"
        type={type}
        value={form[key]}
        placeholder={placeholder}
        disabled={busy}
        aria-invalid={Boolean(errors[key]) || undefined}
        aria-describedby={errors[key] ? `pu-edit-${key}-error` : (hint ? `pu-edit-${key}-hint` : undefined)}
        onChange={(event) => setForm({ ...form, [key]: event.target.value })}
      />
      {hint && !errors[key] && <p className="pu-hint" id={`pu-edit-${key}-hint`}>{hint}</p>}
      <FieldError id={`pu-edit-${key}-error`} message={errors[key]} />
    </div>
  );

  return (
    <Dialog title={`Edit details · ${user.name}`} labelId="pu-edit-title" busy={busy} onClose={onClose}>
      <form onSubmit={submit} noValidate className="pu-form">
        {error && <div className="program-banner error" role="alert">{error}</div>}
        {field('name', 'Name')}
        {field('position', 'Position / office', 'text', 'Optional')}
        {field('email', 'Email', 'email')}
        {field('employee_id', 'Employee ID', 'text', null, 'MSU-00000')}
        <div className="program-field">
          <label htmlFor="pu-edit-contact_number">Contact number (optional)</label>
          <ContactNumberInput
            id="pu-edit-contact_number"
            className="program-input"
            value={form.contact_number}
            disabled={busy}
            aria-invalid={Boolean(errors.contact_number) || undefined}
            aria-describedby={errors.contact_number ? 'pu-edit-contact_number-error pu-edit-contact_number-count' : 'pu-edit-contact_number-count'}
            onValueChange={(value) => setForm({ ...form, contact_number: value })}
          />
          <p className="pu-hint" id="pu-edit-contact_number-count">
            {contactNumberCounter(form.contact_number)} · 11 digits starting with 09. An unchanged number is kept as it is.
          </p>
          <FieldError id="pu-edit-contact_number-error" message={errors.contact_number} />
        </div>
        <AcademicUnitFields
          units={units}
          form={unitForm ?? { college: '', department: '', officeName: '' }}
          onChange={(next) => { setUnitEdit(next); setLocal(({ college: _c, department: _d, office_name: _o, ...rest }) => rest); }}
          errors={errors}
          disabled={busy}
          idPrefix="pu-edit"
          original={user}
          required={false}
        />
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</button>
        </div>
      </form>
    </Dialog>
  );
}

export function ChangeRoleDialog({ user, busy, error, onSave, onClose }) {
  const [role, setRole] = useState(user.role_slug ?? '');
  const [reason, setReason] = useState('');
  const current = ALL_ACCOUNT_ROLE_OPTIONS.find((option) => option.slug === user.role_slug)?.label ?? user.role_label;
  const next = ACCOUNT_ROLE_OPTIONS.find((option) => option.slug === role)?.label;
  const unchanged = role === user.role_slug;

  return (
    <Dialog title={`Change account role · ${user.name}`} labelId="pu-role-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={(event) => { event.preventDefault(); if (!unchanged && !user.is_self) onSave({ role, reason: reason.trim() || null }); }}>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        {user.is_self ? (
          <div className="program-banner info" role="status">You cannot change your own role.</div>
        ) : (
          <>
            <div className="program-field">
              <label htmlFor="pu-role-select">Account role</label>
              <select id="pu-role-select" className="program-select" value={role} disabled={busy} onChange={(event) => setRole(event.target.value)}>
                {/* Program Leader is never offered; shown only as the current role. */}
                {user.role_slug === 'program-leader' && (
                  <option value="program-leader" disabled>Program Leader (current)</option>
                )}
                {ACCOUNT_ROLE_OPTIONS.map((option) => (
                  <option key={option.slug} value={option.slug}>{option.label}</option>
                ))}
              </select>
            </div>
            <div className="program-field">
              <label htmlFor="pu-role-reason">Reason (optional)</label>
              <textarea id="pu-role-reason" className="program-textarea" value={reason} disabled={busy} onChange={(event) => setReason(event.target.value)} />
            </div>
            {!unchanged && (
              <p className="pu-confirm" role="status">
                Change {user.name} from <strong>{current}</strong> to <strong>{next}</strong>? This changes what they can see and do.
                {(user.assignments ?? []).length > 0 && (role === 'program-leader'
                  ? ' A Program Leader has no project assignments: remove theirs first.'
                  : ` Their role on ${user.assignments.length === 1 ? 'their project' : `all ${user.assignments.length} projects`} changes to ${next} too.`)}
              </p>
            )}
          </>
        )}
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          {!user.is_self && (
            <button type="submit" className="program-btn primary" disabled={busy || unchanged}>
              {busy ? 'Saving…' : 'Change role'}
            </button>
          )}
        </div>
      </form>
    </Dialog>
  );
}

export function StatusDialog({ user, busy, error, onSave, onClose }) {
  const deactivating = user.is_active;
  const [reason, setReason] = useState('');
  const [localError, setLocalError] = useState('');

  function submit(event) {
    event.preventDefault();
    if (deactivating && !reason.trim()) {
      setLocalError('A reason is required to deactivate an account.');
      return;
    }
    onSave({ is_active: !deactivating, reason: reason.trim() || null });
  }

  return (
    <Dialog
      title={`${deactivating ? 'Deactivate' : 'Reactivate'} account · ${user.name}`}
      labelId="pu-status-title"
      busy={busy}
      onClose={onClose}
    >
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        {deactivating && user.is_self ? (
          <div className="program-banner info" role="status">You cannot deactivate your own account.</div>
        ) : (
          <>
            <p className="pu-confirm">
              {deactivating
                ? `${user.name} will be signed out and can no longer log in. Their records and history are kept.`
                : `${user.name} will be able to log in again with their existing password.`}
            </p>
            <div className="program-field">
              <label htmlFor="pu-status-reason">Reason{deactivating ? ' (required)' : ' (optional)'}</label>
              <textarea
                id="pu-status-reason"
                className="program-textarea"
                value={reason}
                disabled={busy}
                aria-invalid={Boolean(localError) || undefined}
                aria-describedby={localError ? 'pu-status-reason-error' : undefined}
                onChange={(event) => { setReason(event.target.value); setLocalError(''); }}
              />
              <FieldError id="pu-status-reason-error" message={localError} />
            </div>
          </>
        )}
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          {!(deactivating && user.is_self) && (
            <button type="submit" className={`program-btn ${deactivating ? 'danger' : 'primary'}`} disabled={busy}>
              {busy ? 'Saving…' : deactivating ? 'Deactivate account' : 'Reactivate account'}
            </button>
          )}
        </div>
      </form>
    </Dialog>
  );
}

/**
 * Transfer Program Leader role: pick an active account, confirm with your
 * password. The person leaves their project teams (a Program Leader
 * oversees every project) and you are deactivated and signed out.
 * candidates: active, non-Program-Leader accounts ({ id, name, role_label, project_ids }).
 */
export function TransferProgramLeaderDialog({ candidates, busy, error, fieldErrors = {}, onSave, onClose }) {
  const [userId, setUserId] = useState('');
  const [password, setPassword] = useState('');
  const [local, setLocal] = useState({});
  const errors = { ...fieldErrors, ...local };
  const chosen = (candidates ?? []).find((person) => String(person.id) === userId);
  const projectCount = chosen?.project_ids?.length ?? 0;

  function submit(event) {
    event.preventDefault();
    const next = {};
    if (!userId) next.user_id = 'Choose the new Program Leader.';
    if (!password) next.password = 'Enter your password to confirm.';
    setLocal(next);
    if (Object.keys(next).length) return;
    onSave({ user_id: Number(userId), password });
  }

  return (
    <Dialog title="Transfer Program Leader role" labelId="pu-transfer-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <p className="pu-confirm">
          The system has one Program Leader. The person you choose becomes the Program Leader;
          your account is then deactivated and you are signed out.
        </p>
        <div className="program-field">
          <label htmlFor="pu-transfer-user">New Program Leader</label>
          <select
            id="pu-transfer-user"
            className="program-select"
            value={userId}
            disabled={busy}
            aria-invalid={Boolean(errors.user_id) || undefined}
            aria-describedby={errors.user_id ? 'pu-transfer-user-error' : undefined}
            onChange={(event) => { setUserId(event.target.value); setLocal({ ...local, user_id: '' }); }}
          >
            <option value="">Choose an active account</option>
            {(candidates ?? []).map((person) => (
              <option key={person.id} value={String(person.id)}>
                {person.name}{person.role_label ? ` · ${person.role_label}` : ''}
              </option>
            ))}
          </select>
          <FieldError id="pu-transfer-user-error" message={errors.user_id} />
        </div>
        {chosen && (
          <p className="pu-confirm" role="status">
            <span aria-hidden="true">ℹ</span>{' '}
            {projectCount > 0
              ? `${chosen.name} is removed from ${projectCount === 1 ? 'their project team' : `their ${projectCount} project teams`}, because a Program Leader oversees every project. Their records and history are kept. If they are the only Project Leader of an ongoing project, choose a new Project Leader in that project's set-up first.`
              : `${chosen.name} has no project assignments.`}
          </p>
        )}
        <div className="program-field">
          <label htmlFor="pu-transfer-password">Your password</label>
          <input
            id="pu-transfer-password"
            type="password"
            className="program-input"
            autoComplete="current-password"
            value={password}
            disabled={busy}
            aria-invalid={Boolean(errors.password) || undefined}
            aria-describedby={errors.password ? 'pu-transfer-password-error' : undefined}
            onChange={(event) => { setPassword(event.target.value); setLocal({ ...local, password: '' }); }}
          />
          <FieldError id="pu-transfer-password-error" message={errors.password} />
        </div>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn danger" disabled={busy}>
            {busy ? 'Transferring…' : 'Transfer and sign out'}
          </button>
        </div>
      </form>
    </Dialog>
  );
}

function messageOf(error, fallback) {
  return error?.response?.data?.errors?.reason?.[0]
    || error?.response?.data?.message
    || fallback;
}

/** Warning for an older row whose role differs from the account role. */
export function MismatchWarning({ assignment, user }) {
  return (
    <p className="pu-mismatch" role="note">
      <span aria-hidden="true">⚠</span> Role on this project differs from the account role
      <span className="pu-mismatch-sub"> ({assignment.role_label ?? 'No role'} here, {user.role_label} account)</span>
    </p>
  );
}

/**
 * One assignment in the "Edit project assignments" dialog. The role on the
 * project is the account role (read-only); an older row whose role differs
 * gets a warning and "Fix". Also: remove the person from the project
 * (confirmation + required reason). Pay is kept under Personnel on the
 * project page, not here.
 */
function AssignmentRow({ user, assignment, programWide, onFix, onRemove, onDone }) {
  const [removing, setRemoving] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const base = `pu-asg-${assignment.project_id}`;

  async function run(action, message) {
    setBusy(true);
    setError('');
    try {
      await action();
      onDone(message);
    } catch (requestError) {
      setError(messageOf(requestError, 'Unable to save this change.'));
    } finally {
      setBusy(false);
    }
  }

  function confirmRemove(event) {
    event.preventDefault();
    if (!reason.trim()) {
      setError('A reason is required to remove someone from a project.');
      return;
    }
    run(() => onRemove(assignment.project_id, reason.trim()), `Removed from ${assignment.project}.`);
  }

  return (
    <li className="pu-asg-row">
      <div className="pu-asg-head">
        <Link className="au-link" to={`/program-projects/${assignment.project_id}`}>
          {assignment.project} · {assignment.project_code}
        </Link>
        <div className="au-sub">as {assignment.role_label ?? 'No role'}</div>
      </div>

      {!programWide && assignment.role_mismatch && !removing && (
        <div className="pu-asg-fix">
          <MismatchWarning assignment={assignment} user={user} />
          <button
            type="button"
            className="program-btn primary sm"
            disabled={busy}
            onClick={() => run(() => onFix(assignment.project_id), `Role on ${assignment.project} set to ${user.role_label}.`)}
          >
            {busy ? 'Fixing…' : `Fix: make it ${user.role_label}`}
            <span className="pa-sr-only"> on {assignment.project}</span>
          </button>
        </div>
      )}

      {!programWide && !removing && (
        <div className="pu-asg-pay">
          <Link className="au-link" to={`/program-projects/${assignment.project_id}#personnel-title`}>
            Pay and Special Order: Personnel on the project page
          </Link>
        </div>
      )}

      {removing ? (
        <form className="program-reject" onSubmit={confirmRemove} noValidate>
          <p className="pu-confirm">
            Remove {assignment.project} from this person&apos;s assignments? Their expenses, approvals and audit history stay linked to them.
          </p>
          <div className="program-field">
            <label htmlFor={`${base}-reason`}>Reason (required)</label>
            <textarea
              id={`${base}-reason`}
              className="program-textarea"
              value={reason}
              disabled={busy}
              aria-invalid={Boolean(error) || undefined}
              aria-describedby={error ? `${base}-error` : undefined}
              onChange={(event) => { setReason(event.target.value); setError(''); }}
            />
          </div>
          {error && <p className="program-error" id={`${base}-error`} role="alert">{error}</p>}
          <div className="program-reject-actions">
            <button type="button" className="program-btn" disabled={busy} onClick={() => { setRemoving(false); setReason(''); setError(''); }}>
              Cancel
            </button>
            <button type="submit" className="program-btn danger" disabled={busy}>
              {busy ? 'Removing…' : 'Confirm removal'}
            </button>
          </div>
        </form>
      ) : (
        <>
          {error && <p className="program-error" role="alert">{error}</p>}
          <button type="button" className="pp-clear pu-remove" disabled={busy} onClick={() => { setRemoving(true); setError(''); }}>
            Remove from project
          </button>
        </>
      )}
    </li>
  );
}

/**
 * "Edit project assignments": add a project (through the shared Assign to
 * project dialog, `onAdd`) and fix or remove existing ones.
 */
export function AssignmentsDialog({ user, onAdd, onFix, onRemove, onChanged, onClose }) {
  const [notice, setNotice] = useState('');
  const assignments = user.assignments ?? [];
  const canAdd = user.is_active && !user.program_wide;

  return (
    <Dialog title={`Edit project assignments · ${user.name}`} labelId="pu-asg-title" busy={false} onClose={onClose}>
      <div className="pu-form">
        {user.program_wide && (
          <div className="program-banner info" role="status">
            Program Leaders do not need project assignments. They oversee all projects; you can remove these rows.
          </div>
        )}
        {!user.program_wide && (
          <p className="au-muted">Uses the account role ({user.role_label}).</p>
        )}
        <div aria-live="polite">
          {notice && <div className="program-banner ok">{notice}</div>}
        </div>
        {assignments.length === 0 ? (
          <p className="au-muted">No project assignments.</p>
        ) : (
          <ul className="pu-asg-list">
            {assignments.map((assignment) => (
              <AssignmentRow
                key={`${assignment.project_id}-${assignment.role_slug}`}
                user={user}
                assignment={assignment}
                programWide={user.program_wide}
                onFix={onFix}
                onRemove={onRemove}
                onDone={(message) => { setNotice(message); onChanged(); }}
              />
            ))}
          </ul>
        )}
        {!user.program_wide && !user.is_active && (
          <p className="au-muted">Reactivate the account to add a project.</p>
        )}
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose}>Close</button>
          {canAdd && onAdd && (
            <button type="button" className="program-btn primary" onClick={onAdd}>Add to a project</button>
          )}
        </div>
      </div>
    </Dialog>
  );
}
