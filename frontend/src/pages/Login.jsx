import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ROUTE_BY_ROLE } from './Dashboard';
import { useAuth } from '../context/AuthContext';

function getDashboardPath(roleCode) {
  return ROUTE_BY_ROLE[roleCode] || '/dashboard';
}

export default function Login() {
  const navigate = useNavigate();
  const { signIn } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [keepSignedIn, setKeepSignedIn] = useState(false);
  const [failedAttempts, setFailedAttempts] = useState(0);
  const [lockoutUntil, setLockoutUntil] = useState(null);

  function isLocked() {
    return Boolean(lockoutUntil && Date.now() < lockoutUntil);
  }

  async function handleLogin(e) {
    e?.preventDefault();

    if (isLocked()) {
      const seconds = Math.max(1, Math.ceil((lockoutUntil - Date.now()) / 1000));
      setError(`Too many failed attempts. Please try again in ${seconds}s.`);
      return;
    }

    if (!email || !password) {
      setError('Please fill in all fields.');
      return;
    }

    setSubmitting(true);
    setError('');

    try {
      const user = await signIn(email, password);
      setFailedAttempts(0);
      setLockoutUntil(null);
      navigate(getDashboardPath(user?.role), { replace: true });
    } catch (err) {
      const message = err?.message || '';

      if (message.toLowerCase().includes('awaiting approval')) {
        setError('Your account is awaiting approval');
      } else {
        setError('Invalid email or password');
        setFailedAttempts((prev) => {
          const next = prev + 1;
          if (next >= 5) {
            setLockoutUntil(Date.now() + 30_000);
          }
          return next;
        });
      }
    } finally {
      setSubmitting(false);
    }
  }

  const checklist = [
    'Secure email and password sign-in',
    'Program Leader-approved research access',
    'Real-time fund utilization tracking',
  ];

  return (
    <div className="auth-screen">
      <div className="auth-card">
        <div className="auth-panel auth-panel--brand">
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 32 }}>
              <div className="auth-badge">MSU</div>
              <div className="auth-brand-name">LIB Monitoring System</div>
            </div>

            <h1 className="auth-title">Welcome back</h1>
            <div className="auth-underline" />
            <p className="auth-desc">Monitor externally funded research budgets at Mindanao State University – Main Campus.</p>

            <div className="auth-checklist">
              {checklist.map((item) => (
                <div className="auth-check" key={item}>
                  <span className="auth-check-icon">✓</span>
                  <span>{item}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="auth-footer">MSU–Main Campus · Authorized personnel only</div>
        </div>

        <div className="auth-panel auth-panel--bg">
          <div className="auth-form-wrap">
            <div className="auth-form-card">
              <h2 className="auth-form-title">Sign in</h2>
              <p className="auth-form-sub">Sign in to continue to your research dashboard.</p>

              {error && <div className="auth-error" role="alert">{error}</div>}

              <form onSubmit={handleLogin}>
                <div className="auth-field">
                  <label className="auth-label" htmlFor="login-email">Email address</label>
                  <input
                    id="login-email"
                    className="auth-input"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="email"
                    required
                  />
                </div>

                <div className="auth-field">
                  <div className="auth-field-head">
                    <label className="auth-label" htmlFor="login-password">Password</label>
                    <Link to="/reset-password" className="auth-link">Forgot password?</Link>
                  </div>
                  <div className="auth-input-affix">
                    <input
                      id="login-password"
                      className="auth-input"
                      type={showPassword ? 'text' : 'password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      autoComplete="current-password"
                      required
                    />
                    <button type="button" className="auth-affix-btn" onClick={() => setShowPassword((s) => !s)}>
                      {showPassword ? 'Hide' : 'Show'}
                    </button>
                  </div>
                </div>

                <label className="auth-checkbox">
                  <input
                    type="checkbox"
                    checked={keepSignedIn}
                    onChange={(e) => setKeepSignedIn(e.target.checked)}
                  />
                  <span>Keep me signed in on this device</span>
                </label>

                <button className="auth-btn" type="submit" disabled={submitting}>
                  {submitting ? 'Signing in…' : 'Sign in'}
                </button>
              </form>

              <p className="auth-switch">
                No account yet? <Link to="/register" className="auth-link">Register here</Link>
              </p>

              <div className="auth-note">New accounts are reviewed by the Program Leader before first use.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
