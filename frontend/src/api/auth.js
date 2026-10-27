import client from './client';
import { ENDPOINTS } from './endpoints';
import { ROLE_MAP, SLUG_TO_CODE, normalizeRole } from '../data/mockData';

const USE_MOCK = import.meta.env.VITE_USE_MOCK === 'true';

export function normalizeEmail(raw) {
  return (raw || '').trim().toLowerCase();
}

export function isValidEmail(raw) {
  const email = normalizeEmail(raw);
  // Allowed domains: @gmail.com, @googlemail.com, @msumain.edu.ph
  return /^[^\s@]+@gmail\.com$/.test(email)
      || /^[^\s@]+@googlemail\.com$/.test(email)
      || /^[^\s@]+@msumain\.edu\.ph$/.test(email);
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
    throw new Error('Enter a valid Gmail address.');
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
    if (status === 401) throw new Error('Invalid email or password.');
    throw new Error(err.response?.data?.message || 'Sign in failed. Check your connection and try again.');
  }
}

export async function register({ name: _name, email, password, role, first_name, last_name, employee_id, contact_number, college, department } = {}) {
  const cleanEmail = normalizeEmail(email);
  const cleanFirstName = (first_name || '').trim();
  const cleanLastName = (last_name || '').trim();
  const cleanName = [cleanFirstName, cleanLastName].filter(Boolean).join(' ').trim();

  if (!cleanFirstName || !cleanLastName) {
    throw new Error('First and last name are required.');
  }
  if (!cleanEmail || !isValidEmail(cleanEmail)) {
    throw new Error('Enter a valid Gmail address.');
  }
  if (!password || password.length < 8) {
    throw new Error('Password must be at least 8 characters.');
  }
  if (!role) {
    throw new Error('Please select a role.');
  }

  if (USE_MOCK) {
    if (MOCK_USERS.find((u) => u.email === cleanEmail)) {
      throw new Error('An account with this email already exists.');
    }
    const code = SLUG_TO_CODE[role] ?? null;
    if (!code) throw new Error('Invalid role selected.');
    const roleInfo = ROLE_MAP[code];
    const initials = (cleanName.match(/\b\w/g) || []).slice(0, 2).join('').toUpperCase() || 'U';
    const newUser = {
      id: Date.now(),
      name: cleanName,
      email: cleanEmail,
      role: code,
      role_slug: role,
      roleLabel: roleInfo.label,
      avatar_initials: initials,
    };
    MOCK_USERS.push({ email: cleanEmail, password, code });
    return newUser;
  }

  try {
    await client.get(ENDPOINTS.csrf); // prime the XSRF cookie for Sanctum SPA auth
    const res = await client.post(ENDPOINTS.register, {
      first_name: cleanFirstName,
      last_name: cleanLastName,
      email: cleanEmail,
      password,
      employee_id: employee_id || undefined,
      contact_number: contact_number || undefined,
      college: college || undefined,
      department: department || undefined,
      role,
    });
    const user = res.data.user;
    const code = normalizeRole(user);
    if (!code) throw new Error('Your account has no role assigned. Contact your administrator.');
    return { ...user, email: cleanEmail, role: code };
  } catch (err) {
    const status = err.response?.status;
    if (status === 422) {
      const firstError = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat()[0]
        : null;
      throw new Error(firstError || err.response?.data?.message || 'Registration failed.');
    }
    if (status === 409) {
      throw new Error(err.response?.data?.message || 'An account with this email already exists.');
    }
    throw new Error(err.response?.data?.message || 'Registration failed. Check your connection and try again.');
  }
}

export async function forgotPassword(email) {
  const cleanEmail = normalizeEmail(email);
  if (!cleanEmail || !isValidEmail(cleanEmail)) {
    throw new Error('Enter a valid Gmail address.');
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
    if (status === 422) {
      const firstError = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat()[0]
        : null;
      throw new Error(firstError || err.response?.data?.message || 'Invalid email address.');
    }
    // Always surface a generic message to avoid email enumeration.
    return {
      message: 'If an account exists for that address, a password reset link has been sent.',
    };
  }
}

export async function resetPassword({ email, token, password }) {
  const cleanEmail = normalizeEmail(email);
  if (!cleanEmail || !isValidEmail(cleanEmail)) {
    throw new Error('Enter a valid Gmail address.');
  }
  if (!password || password.length < 8) {
    throw new Error('Password must be at least 8 characters.');
  }
  if (!token) {
    throw new Error('A valid reset token is required.');
  }

  if (USE_MOCK) {
    const found = MOCK_USERS.find((u) => u.email === cleanEmail);
    if (!found) {
      throw new Error('Invalid or expired reset token.');
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
    });
    return res.data;
  } catch (err) {
    const status = err.response?.status;
    if (status === 422) {
      const firstError = err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat()[0]
        : null;
      throw new Error(firstError || err.response?.data?.message || 'Password reset failed.');
    }
    throw new Error(err.response?.data?.message || 'Invalid or expired reset token.');
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
