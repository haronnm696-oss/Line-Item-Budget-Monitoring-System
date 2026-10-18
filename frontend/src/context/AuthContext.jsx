import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import {
  login as loginApi,
  logout as logoutApi,
  fetchCurrentUser,
  register as registerApi,
  forgotPassword as forgotPasswordApi,
  resetPassword as resetPasswordApi,
} from '../api/auth';
import { ROLE_MAP, normalizeRole } from '../data/mockData';

const AuthContext = createContext(null);

const STORAGE_KEY = 'lib_user';

function withNormalizedRole(u) {
  if (!u) return u;
  const code = normalizeRole(u);
  return code ? { ...u, role: code } : u;
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      return withNormalizedRole(raw ? JSON.parse(raw) : null);
    } catch {
      return null;
    }
  });
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    // On first load, ask the backend whether a Sanctum session is still
    // valid (e.g. after a page refresh). No-ops in mock mode.
    fetchCurrentUser().then((serverUser) => {
      if (serverUser) persistUser(serverUser);
      setChecking(false);
    });
  }, []);

  function persistUser(u) {
    const normalized = withNormalizedRole(u);
    setUser(normalized);
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(normalized));
  }

  const signIn = useCallback(async (email, password) => {
    const u = await loginApi(email, password);
    persistUser(u);
    return withNormalizedRole(u);
  }, []);

  const signOut = useCallback(async () => {
    await logoutApi();
    setUser(null);
    sessionStorage.removeItem(STORAGE_KEY);
  }, []);

  const signUp = useCallback(async (credentials) => {
    const u = await registerApi(credentials);
    persistUser(u);
    return withNormalizedRole(u);
  }, []);

  const requestPasswordReset = useCallback(async (email) => {
    return await forgotPasswordApi(email);
  }, []);

  const resetPassword = useCallback(async ({ email, token, password }) => {
    return await resetPasswordApi({ email, token, password });
  }, []);

  useEffect(() => {
    const onUnauthorized = () => { setUser(null); sessionStorage.removeItem(STORAGE_KEY); };
    window.addEventListener('lib:unauthorized', onUnauthorized);
    return () => window.removeEventListener('lib:unauthorized', onUnauthorized);
  }, []);

  const roleCode = normalizeRole(user);
  const roleInfo = roleCode ? ROLE_MAP[roleCode] : null;

  return (
    <AuthContext.Provider
      value={{ user, roleInfo, checking, signIn, signOut, signUp, requestPasswordReset, resetPassword }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
