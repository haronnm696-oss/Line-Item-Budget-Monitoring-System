import assert from 'node:assert/strict';
import test from 'node:test';

import {
  friendlyRegisterError,
  isPersonalPassword,
  isValidEmployeeId,
  isEmailFormat,
  isValidLoginEmail,
  contactNumberCounter,
  contactNumberError,
  CONTACT_NUMBER_MESSAGE,
  isValidContactNumber,
  mapRegisterFieldErrors,
  pasteContactNumber,
  sanitizeContactNumber,
  passwordRules,
} from './authValidation.js';

test('registration and admin-created accounts accept any valid email address', () => {
  for (const email of ['name@s.msumain.edu.ph', 'name@msumain.edu.ph', 'name@gmail.com', 'name@yahoo.com']) {
    assert.equal(isEmailFormat(email), true, email);
  }
  assert.equal(isEmailFormat('not-an-email'), false);
  assert.equal(isEmailFormat('name@nodot'), false);
  assert.equal(isEmailFormat(''), false);
});

test('login accepts any valid email address', () => {
  assert.equal(isValidLoginEmail('name@gmail.com'), true);
  assert.equal(isValidLoginEmail('name@yahoo.com'), true);
  assert.equal(isValidLoginEmail('name@s.msumain.edu.ph'), true);
  assert.equal(isValidLoginEmail('name@msumain.edu.ph'), true);
  assert.equal(isValidLoginEmail('not-an-email'), false);
  assert.equal(isValidLoginEmail(''), false);
});

test('contact number: exactly 11 digits starting with 09', () => {
  assert.equal(isValidContactNumber('09171234567'), true);
  assert.equal(isValidContactNumber('0917123456'), false); // 10 digits
  assert.equal(isValidContactNumber('091712345678'), false); // 12 digits
  assert.equal(isValidContactNumber('0917123456a'), false); // letters
  assert.equal(isValidContactNumber('19171234567'), false); // not 09
  assert.equal(isValidContactNumber('08171234567'), false);
  assert.equal(isValidContactNumber('+639171234567'), false);
  assert.equal(isValidContactNumber(''), false);
});

test('the input keeps digits only and stops at 11', () => {
  assert.equal(sanitizeContactNumber('0917-123-4567'), '09171234567'); // pasted
  assert.equal(sanitizeContactNumber('0917 123 4567'), '09171234567');
  assert.equal(sanitizeContactNumber('09a17b1234567'), '09171234567');
  assert.equal(sanitizeContactNumber('0917123456789'), '09171234567');
  assert.equal(sanitizeContactNumber('+63 917 123 4567'), '09171234567');
  assert.equal(sanitizeContactNumber(''), '');
  assert.equal(contactNumberCounter('0917123'), '7/11');
  assert.equal(contactNumberCounter(''), '0/11');
});

test('contact number errors: optional stays optional, legacy kept only while unchanged', () => {
  assert.equal(contactNumberError('09171234567'), '');
  assert.equal(contactNumberError(''), '');
  assert.equal(contactNumberError('', { required: true }), 'Enter your contact number.');
  assert.equal(contactNumberError('0917123456'), CONTACT_NUMBER_MESSAGE);
  assert.equal(CONTACT_NUMBER_MESSAGE, 'Enter an 11-digit mobile number starting with 09');
  // Edit: an unchanged legacy number is allowed; a changed one must match.
  assert.equal(contactNumberError('+63 917 123 4567', { original: '+63 917 123 4567' }), '');
  assert.equal(contactNumberError('639171234', { original: '+63 917 123 4567' }), CONTACT_NUMBER_MESSAGE);
  assert.equal(contactNumberError('09181234567', { original: '+63 917 123 4567' }), '');
});

test('requires employee IDs in MSU-##### format', () => {
  assert.equal(isValidEmployeeId('MSU-04182'), true);
  assert.equal(isValidEmployeeId('msu-04182'), false);
  assert.equal(isValidEmployeeId('MSU-4182'), false);
  assert.equal(isValidEmployeeId('MSU-041820'), false);
});

test('rejects passwords containing the user name or email local part', () => {
  assert.equal(isPersonalPassword('Juan123!', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph'), false);
  assert.equal(isPersonalPassword('DelaCruz123!', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph'), false);
  assert.equal(isPersonalPassword('juan123!', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph'), false);
  assert.equal(isPersonalPassword('SafePass123!', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph'), true);
  assert.equal(isPersonalPassword('', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph'), false);
});

test('returns the four required password rules with exact labels', () => {
  const rules = passwordRules('SafePass123!', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph');
  assert.deepEqual(
    rules.map((rule) => rule.label),
    ['8+ characters', 'A number', 'A symbol', 'Not your name or email'],
  );
  assert.equal(rules.every((rule) => rule.met), true);
  assert.equal(
    passwordRules('short', 'Juan', 'Dela Cruz', 'juan@msumain.edu.ph').map((rule) => rule.met).join(','),
    'false,false,false,true',
  );
});

test('maps duplicate server messages to clear field errors', () => {
  assert.equal(
    friendlyRegisterError('email', 'The email has already been taken.'),
    'An account with this email already exists.',
  );
  assert.equal(
    friendlyRegisterError('employee_id', 'The employee id has already been taken.'),
    'This employee ID is already registered.',
  );
  assert.equal(
    friendlyRegisterError('contact_number', 'Any server text'),
    'Enter an 11-digit mobile number starting with 09',
  );
  assert.equal(
    friendlyRegisterError('email', 'The email field format is invalid.'),
    'Enter a valid email address.',
  );
  assert.equal(friendlyRegisterError('first_name', 'The first name is required.'), 'The first name is required.');
});

test('maps the backend error bag to friendly per-field messages', () => {
  const mapped = mapRegisterFieldErrors({
    email: ['The email has already been taken.'],
    first_name: ['The first name field is required.'],
  });
  assert.deepEqual(mapped, {
    email: 'An account with this email already exists.',
    first_name: 'The first name field is required.',
  });
});

test('a pasted "0917-123-4567" becomes 09171234567 (before maxLength can cut it)', () => {
  assert.equal(pasteContactNumber('', '0917-123-4567', 0, 0), '09171234567');
  assert.equal(pasteContactNumber('0917', '-123-4567', 4, 4), '09171234567');
  assert.equal(pasteContactNumber('09999999999', '0917-123-4567', 0, 11), '09171234567'); // replaces a selection
  assert.equal(pasteContactNumber('', '+63 917 123 4567'), '09171234567');
});
