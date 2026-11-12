import client from './client';
import { ENDPOINTS } from './endpoints';
import { ROLE_MAP, normalizeRole } from '../data/mockData';
import {
  isValidLoginEmail,
  isValidStudentEmail,
  mapRegisterFieldErrors,
  normalizePhMobile,
} from '../lib/authValidation';

const USE_MOCK = import.meta.env.VITE_USE_MOCK === 'true';

export function normalizeEmail(raw) {
  return (raw || '').trim().toLowerCase();
}

export function isValidEmail(raw) {
  // Keep this aligned with the backend login rule: Gmail demo accounts plus
  // the student (@s.) and faculty/staff MSU domains.
  return isValidLoginEmail(normalizeEmail(raw));
}

// Demo accounts used only when VITE_USE_MOCK=true. Passwords are verified
// here so mock mode behaves like the real backend.
const MOCK_USERS = [
  { email: 'program.leader@gmail.com', password: 'password123', code: 'PRG' },
  { email: 'project.leader@gmail.com', password: 'password123', code: 'PL' },
  { email: 'researcher@gmail.com', password: 'password123', code: 'RES' },
  { email: 'assistant@gmail.com', password: 'password123', code: 'RA' },
  { email: 'finance@gmail.com', password: 'password123', code: 'FIN' },
  // Demo / sample accounts (default demo password: Password123!)
  { email: 'ilham.gaml@msumain.edu.ph', password: 'Password123!', code: 'PRG' },
  { email: 'project.leader@msumain.edu.ph', password: 'Password123!', code: 'PL' },
  { email: 'ra.sample@msumain.edu.ph', password: 'Password123!', code: 'RA' },
  { email: 'researcher.sample@msumain.edu.ph', password: 'Password123!', code: 'RES' },
];

function toMockUser(code, email) {
  const role = ROLE_MAP[code];
  const name = role.name;
  const initials = role.initials;
  return { id: 0, name, email, role: code, role_slug: role.slug, roleLabel: role.label, avatar_initials: initials };
}

export async function login(email, password) {
  const cleanEmail = normalizeEmail(email);
  if (!cleanEmail || !isValidEmail(cleanEmail)) {
    throw new Error('Enter a valid Gmail or MSU-Main email address.');
  }
  if (!password || password.length < 8) {
    throw new Error('Password must be at least 8 characters.');
  }

  if (USE_MOCK) {
    const found = MOCK_USERS.find((u) => u.email === cleanEmail);
    if (!found || found.password !== password) {
      throw new Error('Invalid email or password.');
    }
    return toMockUser(found.code, cleanEmail);
  }

  try {
    await client.get(ENDPOINTS.csrf); // prime the XSRF cookie for Sanctum SPA auth
    const res = await client.post(ENDPOINTS.login, { email: cleanEmail, password });
    const user = res.data.user;
    const code = normalizeRole(user);
    if (!code) throw new Error('Your account has no role assigned. Contact your administrator.');
    return { ...user, email: cleanEmail, role: code };
  } catch (err) {
    if (err.message === 'Your account has no role assigned. Contact your administrator.') throw err;
    const status = err.response?.status;
    if (status === 422) {
      const firstError = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat()[0]
        : null;
      throw new Error(firstError || err.response?.data?.message || 'Invalid email or password.');
    }
    if (status === 403) throw new Error(err.response?.data?.message || 'This account is deactivated.');
    if (status === 429) throw new Error('Too many sign-in attempts. Please wait a minute and try again.');
    if (status === 401) throw new Error('Invalid email or password.');
    throw new Error(err.response?.data?.message || 'Sign in failed. Check your connection and try again.');
  }
}

export async function register({ name: _name, email, password, first_name, last_name, employee_id, contact_number, college, department, position } = {}) {
  const cleanEmail = normalizeEmail(email);
  const cleanFirstName = (first_name || '').trim();
  const cleanLastName = (last_name || '').trim();
  const cleanName = [cleanFirstName, cleanLastName].filter(Boolean).join(' ').trim();

  if (!cleanFirstName || !cleanLastName) {
    throw new Error('First and last name are required.');
  }
  if (!cleanEmail || !isValidStudentEmail(cleanEmail)) {
    throw new Error('Please use your @s.msumain.edu.ph student email address.');
  }
  if (!password || password.length < 8) {
    throw new Error('Password must be at least 8 characters.');
  }

  const cleanContact = contact_number ? normalizePhMobile(contact_number) : '';

  if (USE_MOCK) {
    if (MOCK_USERS.find((u) => u.email === cleanEmail)) {
      throw Object.assign(new Error('An account with this email already exists.'), {
        fieldErrors: { email: 'An account with this email already exists.' },
      });
    }
    const initials = (cleanName.match(/\b\w/g) || []).slice(0, 2).join('').toUpperCase() || 'U';
    return {
      pending: true,
      message: 'Account submitted. A Program Leader must approve it and assign a role before you can sign in.',
      user: {
        id: Date.now(),
        name: cleanName,
        email: cleanEmail,
        role: null,
        role_slug: null,
        roleLabel: 'Pending approval',
        status: 'Pending',
        is_active: false,
        avatar_initials: initials,
      },
    };
  }

  try {
    await client.get(ENDPOINTS.csrf); // prime the XSRF cookie for Sanctum SPA auth
    const res = await client.post(ENDPOINTS.register, {
      first_name: cleanFirstName,
      last_name: cleanLastName,
      email: cleanEmail,
      password,
      employee_id: employee_id || undefined,
      contact_number: cleanContact || undefined,
      college: college || undefined,
      department: department || undefined,
      position: position || undefined,
    });
    return res.data;
  } catch (err) {
    const status = err.response?.status;
    const rawFieldErrors = err.response?.data?.errors || null;
    const fieldErrors = rawFieldErrors ? mapRegisterFieldErrors(rawFieldErrors) : null;
    const serverMessage = err.response?.data?.message;
    const safeServerMessage = serverMessage && !/server error/i.test(serverMessage)
      ? serverMessage
      : 'Registration failed. Check your connection and try again.';

    if (status === 422) {
      const firstError = fieldErrors ? Object.values(fieldErrors)[0] : null;
      const error = new Error(firstError || safeServerMessage || 'Registration failed.');
      error.fieldErrors = fieldErrors;
      error.status = status;
      throw error;
    }
    if (status === 409) {
      const message = err.response?.data?.message || 'An account with this email already exists.';
      const error = new Error(message);
      error.fieldErrors = { email: 'An account with this email already exists.' };
      error.status = status;
      throw error;
    }
    const error = new Error(safeServerMessage || 'Registration failed. Check your connection and try again.');
    error.status = status;
    throw error;
  }
}

export async function forgotPassword(email) {
  const cleanEmail = normalizeEmail(email);
  if (!cleanEmail || !isValidLoginEmail(cleanEmail)) {
    throw new Error('Enter a valid MSU-Main or Gmail email address.');
  }

  if (USE_MOCK) {
    // Mock mode: don't reveal whether the email exists.
    return {
      message: 'If an account exists for that address, a password reset link has been sent.',
    };
  }

  try {
    await client.get(ENDPOINTS.csrf);
    const res = await client.post(ENDPOINTS.forgotPassword, { email: cleanEmail });
    return res.data;
  } catch (err) {
    const status = err.response?.status;
    const errors = err.response?.data?.errors;
    if (status === 422) {
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      const error = new Error(firstError || err.response?.data?.message || 'Enter a valid email address.');
      error.status = status;
      error.errors = errors;
      throw error;
    }
    if (status === 429) {
      const error = new Error('Too many reset requests. Please wait before requesting another link.');
      error.status = status;
      throw error;
    }
    const error = new Error('We could not send the reset link. Check your connection and try again.');
    error.status = status;
    throw error;
  }
}

export async function resetPassword({ email, token, password, passwordConfirmation }) {
  const cleanEmail = normalizeEmail(email);
  if (!cleanEmail || !isValidLoginEmail(cleanEmail)) {
    throw new Error('This reset link is invalid or has expired.');
  }
  if (!token) {
    throw new Error('This reset link is invalid or has expired.');
  }
  if (!password) {
    throw new Error('Enter a new password.');
  }

  if (USE_MOCK) {
    const found = MOCK_USERS.find((u) => u.email === cleanEmail);
    if (!found) {
      const error = new Error('This reset link is invalid or has expired.');
      error.status = 422;
      error.errors = { token: ['This reset link is invalid or has expired.'] };
      throw error;
    }
    found.password = password;
    return { message: 'Your password has been reset. You can now sign in.' };
  }

  try {
    await client.get(ENDPOINTS.csrf);
    const res = await client.post(ENDPOINTS.resetPassword, {
      email: cleanEmail,
      token,
      password,
      password_confirmation: passwordConfirmation ?? password,
    });
    return res.data;
  } catch (err) {
    const status = err.response?.status;
    const errors = err.response?.data?.errors;
    if (status === 422) {
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      const error = new Error(firstError || err.response?.data?.message || 'This reset link is invalid or has expired.');
      error.status = status;
      error.errors = errors;
      throw error;
    }
    if (status === 429) {
      const error = new Error('Too many requests. Please wait before trying again.');
      error.status = status;
      throw error;
    }
    const error = new Error('We could not reset your password. Check your connection and try again.');
    error.status = status;
    throw error;
  }
}

export async function logout() {
  if (USE_MOCK) return;
  try {
    await client.post(ENDPOINTS.logout);
  } catch {
    // best-effort — clearing local session state still happens in AuthContext
  }
}

export async function fetchCurrentUser() {
  if (USE_MOCK) return null;
  try {
    const res = await client.get(ENDPOINTS.me);
    const user = res.data.user;
    const code = normalizeRole(user);
    if (!code) return null;
    return { ...user, role: code };
  } catch {
    return null;
  }
}
