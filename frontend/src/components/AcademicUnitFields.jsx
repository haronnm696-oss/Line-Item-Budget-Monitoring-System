import { chooseCollege, findUnit, unitFieldsFor, unitFormFromUser } from '../lib/academicUnits';

/**
 * College / unit, then Department (only for units with departments) or the
 * office name ("Other office / unit"), from the one list (useAcademicUnits).
 * Changing the college clears the department. A saved value that is not in
 * the list (`original`, a user) is shown as it is, marked "(saved value)".
 * Used by Add user and Edit details; Create account renders its own styled
 * fields with the same helpers.
 */
export default function AcademicUnitFields({ units, form, onChange, errors = {}, disabled = false, idPrefix, original = null, required = true }) {
  if (units.status === 'loading' || units.status === 'idle') {
    return <p className="ld-muted" aria-busy="true">Loading colleges and units…</p>;
  }
  if (units.status === 'error') {
    return (
      <div className="program-banner error" role="alert">
        Could not load the list of colleges and units.{' '}
        <button type="button" className="program-btn sm" onClick={units.retry}>Try again</button>
      </div>
    );
  }

  const list = units.data;
  const saved = original ? unitFormFromUser(original, list) : null;
  const legacyCollege = saved?.college && !findUnit(list, saved.college) ? saved.college : null;
  const { showDepartment, showOffice, departments, officeLabel, officeMax } = unitFieldsFor(list, form.college);
  // A saved department not in its (listed or old) college stays selectable while that college is chosen.
  const legacyDepartment = saved?.department && form.college === saved.college && !departments.includes(saved.department)
    ? saved.department : null;
  const id = (name) => `${idPrefix}-${name}`;
  const described = (name) => (errors[name] ? `${id(name)}-error` : undefined);
  const error = (name) => errors[name] && <p className="program-error" id={`${id(name)}-error`}>{errors[name]}</p>;

  return (
    <>
      <div className="program-field">
        <label htmlFor={id('college')}>College or unit{required ? '' : ' (optional)'}</label>
        <select
          id={id('college')}
          className="program-select"
          value={form.college}
          disabled={disabled}
          aria-invalid={Boolean(errors.college) || undefined}
          aria-describedby={described('college')}
          onChange={(event) => onChange(chooseCollege(form, event.target.value))}
        >
          <option value="">Select a college or unit</option>
          {legacyCollege && <option value={legacyCollege}>{legacyCollege} (saved value)</option>}
          {list.units.map((unit) => <option key={unit.name} value={unit.name}>{unit.name}</option>)}
        </select>
        {error('college')}
      </div>

      {(showDepartment || legacyDepartment) && (
        <div className="program-field">
          <label htmlFor={id('department')}>Department</label>
          <select
            id={id('department')}
            className="program-select"
            value={form.department}
            disabled={disabled}
            aria-invalid={Boolean(errors.department) || undefined}
            aria-describedby={described('department')}
            onChange={(event) => onChange({ ...form, department: event.target.value })}
          >
            <option value="">Select a department</option>
            {legacyDepartment && <option value={legacyDepartment}>{legacyDepartment} (saved value)</option>}
            {departments.map((department) => <option key={department} value={department}>{department}</option>)}
          </select>
          {error('department')}
        </div>
      )}

      {showOffice && (
        <div className="program-field">
          <label htmlFor={id('office')}>{officeLabel}</label>
          <input
            id={id('office')}
            className="program-input"
            value={form.officeName}
            maxLength={officeMax}
            disabled={disabled}
            required
            aria-invalid={Boolean(errors.office_name) || undefined}
            aria-describedby={errors.office_name ? `${id('office_name')}-error` : undefined}
            onChange={(event) => onChange({ ...form, officeName: event.target.value })}
          />
          {error('office_name')}
        </div>
      )}
    </>
  );
}
