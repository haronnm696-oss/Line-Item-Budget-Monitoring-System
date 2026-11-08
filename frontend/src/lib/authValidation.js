const MSU_EMAIL_RE = /^[^\s@]+@msumain\.edu\.ph$/i;
const PH_MOBILE_RE = /^(09\d{9}|\+639\d{9})$/;
const EMPLOYEE_ID_RE = /^MSU-\d{5}$/;

function compactPhone(value) {
  return String(value || '')
    .trim()
    .replace(/[\s()-]/g, '');
}

export function normalizePhMobile(value) {
  const raw = compactPhone(value);
  if (/^\+639\d{9}$/.test(raw)) return `0${raw.slice(3)}`;
  if (/^639\d{9}$/.test(raw)) return `0${raw.slice(2)}`;
  return raw;
}

export function isValidMsuEmail(value) {
  return MSU_EMAIL_RE.test(String(value || '').trim());
}

export function isValidPhMobile(value) {
  return PH_MOBILE_RE.test(compactPhone(value));
}

export function isValidEmployeeId(value) {
  return EMPLOYEE_ID_RE.test(String(value || '').trim());
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
    if (/msumain|institutional|domain/i.test(text)) {
      return 'Use your @msumain.edu.ph email address.';
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
    return 'Enter a valid PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).';
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
