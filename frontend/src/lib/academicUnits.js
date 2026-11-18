// MSU-Main colleges / units and departments. The list itself comes from the
// backend (GET /api/academic-units, config/academic_units.php) and is never
// typed here. These helpers apply the same rule as App\Support\AcademicUnits.
// Kept free of JSX so it can be tested.

/** The unit named `name`, or null. */
export function findUnit(list, name) {
  return (list?.units ?? []).find((unit) => unit.name === name) ?? null;
}

/**
 * Form state from a saved user: { college, department, officeName }. For
 * "Other office / unit" the office name is stored as the department.
 */
export function unitFormFromUser(user, list) {
  const college = user?.college ?? '';
  const other = findUnit(list, college)?.other === true;
  return { college, department: other ? '' : (user?.department ?? ''), officeName: other ? (user?.department ?? '') : '' };
}

/** New state after choosing a college: the department (and office name) are cleared. */
export function chooseCollege(form, college) {
  return { ...form, college, department: '', officeName: '' };
}

/** What the Department / office fields look like for the chosen unit. */
export function unitFieldsFor(list, college) {
  const unit = findUnit(list, college);
  return {
    unit,
    showDepartment: Boolean(unit && !unit.other && unit.departments.length > 0),
    showOffice: Boolean(unit?.other),
    departments: unit?.departments ?? [],
    officeLabel: list?.other?.office_label ?? 'Office or unit name',
    officeMax: list?.other?.office_max ?? 150,
  };
}

function sameSaved(form, original, list) {
  if (!original) return false;
  const saved = unitFormFromUser(original, list);
  const clean = (value) => String(value ?? '').trim();
  return clean(form.college) === clean(saved.college)
    && clean(form.department) === clean(saved.department)
    && clean(form.officeName) === clean(saved.officeName);
}

/**
 * Field errors ({ college?, department?, office_name? }) for the form. A
 * saved pair that is not in the list (`original`, a user) is accepted while
 * unchanged. `required` false lets an empty college through.
 */
export function academicUnitErrors(form, list, { required = true, original = null } = {}) {
  if (sameSaved(form, original, list)) return {};
  const college = String(form.college ?? '').trim();
  if (!college) return required ? { college: 'Select your college or unit.' } : {};
  const { unit, showDepartment, showOffice, departments, officeMax } = unitFieldsFor(list, college);
  if (!unit) return { college: 'Select a college or unit from the list.' };
  if (showOffice) {
    const office = String(form.officeName ?? '').trim();
    if (!office) return { office_name: 'Enter the office or unit name.' };
    if (office.length > officeMax) return { office_name: `The office or unit name may not be longer than ${officeMax} characters.` };
    return {};
  }
  if (!showDepartment) return {};
  const department = String(form.department ?? '').trim();
  if (!department) return { department: 'Select your department.' };
  if (!departments.includes(department)) return { department: `Select a department of the ${college}.` };
  return {};
}

/** Request fields: { college, department, office_name } (null when not used). */
export function academicUnitPayload(form, list) {
  const { showDepartment, showOffice } = unitFieldsFor(list, form.college);
  const college = String(form.college ?? '').trim();
  return {
    college: college || null,
    department: showDepartment || !findUnit(list, college) ? (String(form.department ?? '').trim() || null) : null,
    office_name: showOffice ? (String(form.officeName ?? '').trim() || null) : null,
  };
}

/** "College of Engineering, Civil Engineering" for lists (old values shown as they are). */
export function unitText(user) {
  return [user?.college, user?.department].filter(Boolean).join(', ');
}
