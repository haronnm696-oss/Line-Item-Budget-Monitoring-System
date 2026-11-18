const STUDENT_EMAIL_RE = /^[^\s@]+@s\.msumain\.edu\.ph$/i;
const MSU_EMAIL_RE = /^[^\s@]+@(?:s\.)?msumain\.edu\.ph$/i;
const LOGIN_EMAIL_RE = /^[^\s@]+@(?:gmail\.com|(?:s\.)?msumain\.edu\.ph)$/i;
const EMAIL_FORMAT_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const EMPLOYEE_ID_RE = /^MSU-\d{5}$/;

// Contact number: exactly 11 digits starting with 09 (PH mobile, e.g.
// 09171234567). The backend applies the same rule (User::CONTACT_NUMBER_REGEX).
const CONTACT_NUMBER_RE = /^09\d{9}$/;
export const CONTACT_NUMBER_LENGTH = 11;
export const CONTACT_NUMBER_MESSAGE = 'Enter an 11-digit mobile number starting with 09';

/**
 * What the contact number input keeps as the user types or pastes: digits
 * only (spaces, dashes, letters dropped), at most 11. A pasted full
 * international number (+63 917 123 4567) becomes 09171234567.
 */
export function sanitizeContactNumber(value) {
  let digits = String(value ?? '').replace(/\D/g, '');
  if (/^639\d{9}$/.test(digits)) digits = `0${digits.slice(2)}`;
  return digits.slice(0, CONTACT_NUMBER_LENGTH);
}

/**
 * The field's value after a paste of `pasted` over the selection
 * [start, end) of `current`, sanitized as a whole. Handled before the
 * browser applies maxLength, which would otherwise cut "0917-123-4567".
 */
export function pasteContactNumber(current, pasted, start, end) {
  const text = String(current ?? '');
  const from = start ?? text.length;
  const to = end ?? from;
  return sanitizeContactNumber(text.slice(0, from) + String(pasted ?? '') + text.slice(to));
}

export function isValidContactNumber(value) {
  return CONTACT_NUMBER_RE.test(String(value ?? ''));
}

/** "7/11" under the field. */
export function contactNumberCounter(value) {
  return `${String(value ?? '').length}/${CONTACT_NUMBER_LENGTH}`;
}

/**
 * '' when valid, else the message. Empty is fine unless `required`. On an
 * edit, a saved number that does not match is accepted only while it is
 * unchanged (`original`), like legacy emails and employee IDs.
 */
export function contactNumberError(value, { required = false, original } = {}) {
  const text = String(value ?? '');
  if (!text) return required ? 'Enter your contact number.' : '';
  if (original != null && text === String(original)) return '';
  return isValidContactNumber(text) ? '' : CONTACT_NUMBER_MESSAGE;
}

export function isValidStudentEmail(value) {
  return STUDENT_EMAIL_RE.test(String(value || '').trim());
}

export function isValidMsuEmail(value) {
  return MSU_EMAIL_RE.test(String(value || '').trim());
}

export function isValidLoginEmail(value) {
  return LOGIN_EMAIL_RE.test(String(value || '').trim());
}

export function isValidEmployeeId(value) {
  return EMPLOYEE_ID_RE.test(String(value || '').trim());
}

export function isEmailFormat(value) {
  return EMAIL_FORMAT_RE.test(String(value || '').trim());
}

export function isPersonalPassword(password, firstName, lastName, email) {
  const value = String(password || '').toLowerCase();
  if (!value) return false;

  const localPart = String(email || '').split('@')[0];
  const personalParts = [
    firstName,
    lastName,
    `${firstName || ''}${lastName || ''}`,
    `${firstName || ''} ${lastName || ''}`,
    localPart,
  ]
    .map((part) => String(part || '').trim().toLowerCase())
    .filter((part) => part.length >= 3)
    .flatMap((part) => [part, part.replace(/\s+/g, '')]);

  return !personalParts.some((part) => value.includes(part));
}

export function passwordRules(password, firstName, lastName, email) {
  const value = String(password || '');

  return [
    { key: 'length', label: '8+ characters', met: value.length >= 8 },
    { key: 'number', label: 'A number', met: /\d/.test(value) },
    { key: 'symbol', label: 'A symbol', met: /[^A-Za-z0-9]/.test(value) },
    {
      key: 'personal',
      label: 'Not your name or email',
      met: isPersonalPassword(value, firstName, lastName, email),
    },
  ];
}

export function friendlyRegisterError(field, message) {
  const text = String(message || '').trim();

  if (field === 'email') {
    if (/already|taken|exists|unique/i.test(text)) {
      return 'An account with this email already exists.';
    }
    if (/msumain|institutional|domain|format|regex/i.test(text)) {
      return 'Use your @s.msumain.edu.ph student email address.';
    }
    return text || 'Enter a valid email address.';
  }

  if (field === 'employee_id') {
    if (/already|taken|exists|unique/i.test(text)) {
      return 'This employee ID is already registered.';
    }
    if (/format|regex|invalid/i.test(text)) {
      return 'Use the format MSU-##### (5 digits).';
    }
    return text || 'Use the format MSU-##### (5 digits).';
  }

  if (field === 'contact_number') {
    return /required/i.test(text) ? 'Enter your contact number.' : CONTACT_NUMBER_MESSAGE;
  }

  return text;
}

export function mapRegisterFieldErrors(fieldErrors = {}) {
  return Object.fromEntries(
    Object.entries(fieldErrors || {}).map(([field, messages]) => {
      const message = Array.isArray(messages) ? messages[0] : messages;
      return [field, friendlyRegisterError(field, message)];
    }),
  );
}
