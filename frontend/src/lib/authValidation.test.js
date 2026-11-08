import assert from 'node:assert/strict';
import test from 'node:test';

import {
  friendlyRegisterError,
  isPersonalPassword,
  isValidEmployeeId,
  isValidMsuEmail,
  isValidPhMobile,
  mapRegisterFieldErrors,
  normalizePhMobile,
  passwordRules,
} from './authValidation.js';

test('accepts only @msumain.edu.ph email addresses', () => {
  assert.equal(isValidMsuEmail('name@msumain.edu.ph'), true);
  assert.equal(isValidMsuEmail('NAME@MSUMAIN.EDU.PH'), true);
  assert.equal(isValidMsuEmail('name@gmail.com'), false);
  assert.equal(isValidMsuEmail('name@msumain.edu.ph.evil.com'), false);
  assert.equal(isValidMsuEmail(''), false);
});

test('accepts both PH mobile formats and normalizes +63 to 09', () => {
  assert.equal(isValidPhMobile('09171234567'), true);
  assert.equal(isValidPhMobile('+639171234567'), true);
  assert.equal(isValidPhMobile('+63 917 123 4567'), true);
  assert.equal(isValidPhMobile('12345678901'), false);
  assert.equal(normalizePhMobile('+63 917 123 4567'), '09171234567');
  assert.equal(normalizePhMobile('+639171234567'), '09171234567');
  assert.equal(normalizePhMobile('639171234567'), '09171234567');
  assert.equal(normalizePhMobile('09171234567'), '09171234567');
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
    'Enter a valid PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).',
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
