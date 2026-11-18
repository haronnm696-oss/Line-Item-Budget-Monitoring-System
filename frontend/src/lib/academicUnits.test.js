import assert from 'node:assert/strict';
import test from 'node:test';

import { academicUnitErrors, academicUnitPayload, chooseCollege, unitFieldsFor, unitFormFromUser, unitText } from './academicUnits.js';

// The shape GET /api/academic-units returns (a small slice of the list).
const list = {
  units: [
    { name: 'College of Engineering', departments: ['Civil Engineering', 'Mechanical Engineering', 'Graduate Studies'], other: false },
    { name: 'College of Law', departments: [], other: false },
    { name: 'College of Natural Sciences and Mathematics', departments: ['Biology', 'Mathematics', 'Graduate Studies'], other: false },
    { name: 'Other office / unit', departments: [], other: true },
  ],
  other: { name: 'Other office / unit', office_label: 'Office or unit name', office_max: 150 },
};
const form = (college, department = '', officeName = '') => ({ college, department, officeName });

test('a valid college and department are accepted', () => {
  assert.deepEqual(academicUnitErrors(form('College of Engineering', 'Civil Engineering'), list), {});
  assert.deepEqual(academicUnitErrors(form('College of Engineering', 'Graduate Studies'), list), {});
  assert.deepEqual(academicUnitPayload(form('College of Engineering', 'Civil Engineering'), list),
    { college: 'College of Engineering', department: 'Civil Engineering', office_name: null });
});

test('a department from another college is rejected', () => {
  assert.deepEqual(academicUnitErrors(form('College of Engineering', 'Biology'), list),
    { department: 'Select a department of the College of Engineering.' });
  assert.deepEqual(academicUnitErrors(form('College of Engineering'), list), { department: 'Select your department.' });
  assert.deepEqual(academicUnitErrors(form('College of Business and Finance', 'Finance'), list), { college: 'Select a college or unit from the list.' });
  assert.deepEqual(academicUnitErrors(form(''), list), { college: 'Select your college or unit.' });
});

test('a unit without departments hides the field and needs none', () => {
  assert.equal(unitFieldsFor(list, 'College of Law').showDepartment, false);
  assert.deepEqual(academicUnitErrors(form('College of Law'), list), {});
  assert.equal(academicUnitPayload(form('College of Law', 'stale'), list).department, null);
});

test('"Other office / unit" requires the office name (max 150)', () => {
  const fields = unitFieldsFor(list, 'Other office / unit');
  assert.equal(fields.showOffice, true);
  assert.equal(fields.showDepartment, false);
  assert.deepEqual(academicUnitErrors(form('Other office / unit'), list), { office_name: 'Enter the office or unit name.' });
  assert.deepEqual(academicUnitErrors(form('Other office / unit', '', 'x'.repeat(151)), list),
    { office_name: 'The office or unit name may not be longer than 150 characters.' });
  assert.deepEqual(academicUnitPayload(form('Other office / unit', '', ' OVCRE '), list), { college: 'Other office / unit', department: null, office_name: 'OVCRE' });
});

test('changing the college clears the department', () => {
  assert.deepEqual(chooseCollege(form('College of Engineering', 'Civil Engineering', 'x'), 'College of Law'), form('College of Law'));
});

test('a saved value not in the list is allowed while unchanged, shown as it is', () => {
  const legacy = { college: 'College of Business and Finance', department: 'Finance' };
  const start = unitFormFromUser(legacy, list);
  assert.deepEqual(start, form('College of Business and Finance', 'Finance'));
  assert.deepEqual(academicUnitErrors(start, list, { original: legacy }), {});
  assert.deepEqual(academicUnitPayload(start, list), { college: 'College of Business and Finance', department: 'Finance', office_name: null });
  // Changed: must come from the list.
  assert.deepEqual(academicUnitErrors({ ...start, department: 'Accounting' }, list, { original: legacy }), { college: 'Select a college or unit from the list.' });
  assert.equal(unitText(legacy), 'College of Business and Finance, Finance');
  // An "Other" user's office name comes back into the office field.
  assert.deepEqual(unitFormFromUser({ college: 'Other office / unit', department: 'OVCRE' }, list), form('Other office / unit', '', 'OVCRE'));
  // Edit details is optional: an empty college is fine.
  assert.deepEqual(academicUnitErrors(form(''), list, { required: false }), {});
});
