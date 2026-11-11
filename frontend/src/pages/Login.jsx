import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ROUTE_BY_ROLE } from './Dashboard';
import { useAuth } from '../context/AuthContext';

function getDashboardPath(roleCode) {
  return ROUTE_BY_ROLE[roleCode] || '/dashboard';
}

function MailIcon() {
  return (
    <svg
      className="signin-control__icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <rect x="3" y="5" width="18" height="14" rx="2.5" />
      <path d="m4 7 8 6 8-6" />
    </svg>
  );
}

function LockIcon() {
  return (
    <svg
      className="signin-control__icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <rect x="4" y="10" width="16" height="11" rx="2.5" />
      <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    </svg>
  );
}

function hideBrokenImage(event) {
  event.currentTarget.style.display = 'none';
}

export default function Login() {
  const navigate = useNavigate();
  const { signIn } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [capsLockOn, setCapsLockOn] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [keepSignedIn, setKeepSignedIn] = useState(false);
  const [failedAttempts, setFailedAttempts] = useState(0);
  const [lockoutUntil, setLockoutUntil] = useState(null);

  function isLocked() {
    return Boolean(lockoutUntil && Date.now() < lockoutUntil);
  }

  function updateCapsLock(event) {
    if (typeof event.getModifierState === 'function') {
      setCapsLockOn(event.getModifierState('CapsLock'));
    }
  }

  async function handleLogin(e) {
    e.preventDefault();

    if (isLocked()) {
      const seconds = Math.max(1, Math.ceil((lockoutUntil - Date.now()) / 1000));
      setError(`Too many failed attempts. Please try again in ${seconds}s.`);
      return;
    }

    setSubmitting(true);
    setError('');

    try {
      const user = await signIn(email.trim(), password);
      setFailedAttempts(0);
      setLockoutUntil(null);
      navigate(getDashboardPath(user?.role), { replace: true });
    } catch (err) {
      const message = (err?.message || '').toLowerCase();

      if (message.includes('awaiting') || message.includes('pending') || message.includes('approval')) {
        setError('Your account is awaiting approval.');
      } else if (message.includes('too many')) {
        setError('Too many sign-in attempts. Please wait a minute and try again.');
      } else {
        setError('Invalid email or password.');
        const nextAttempts = failedAttempts + 1;
        setFailedAttempts(nextAttempts);
        if (nextAttempts >= 5) {
          setLockoutUntil(Date.now() + 30_000);
        }
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="signin-page">
      <div className="signin-card">
        <aside className="signin-brand">
          <span className="signin-brand__shape signin-brand__shape--circle" aria-hidden="true" />
          <span className="signin-brand__shape signin-brand__shape--diamond signin-brand__shape--diamond-top" aria-hidden="true" />
          <span className="signin-brand__shape signin-brand__shape--diamond signin-brand__shape--diamond-bottom" aria-hidden="true" />

          <div className="signin-brand__inner">
            <div className="signin-brand__logos">
              <img
                className="signin-brand__logo"
                src="/images/msu-seal-outline.png"
                alt="Mindanao State University seal"
                onError={hideBrokenImage}
              />
              <img
                className="signin-brand__logo"
                src="/images/client-logo-outline.png"
                alt="Client logo"
                onError={hideBrokenImage}
              />
            </div>

            <h2 className="signin-brand__title">Welcome back</h2>
            <p className="signin-brand__system">LIB Monitoring System</p>
            <p className="signin-brand__lead">
              Monitor externally funded research budgets at Mindanao State University – Main Campus.
            </p>

            <Link to="/register" className="signin-brand__cta">
              Request access
            </Link>
          </div>

          <p className="signin-brand__footer">MSU–Main Campus · Authorized personnel only</p>
        </aside>

        <main className="signin-main">
          <div className="signin-mobile-head">
            <img
              src="/images/msu-seal.png"
              alt="Mindanao State University seal"
              onError={hideBrokenImage}
            />
            <span>LIB Monitoring System</span>
          </div>

          <div className="signin-head">
            <h1 className="signin-title">Sign in</h1>
            <p className="signin-subtitle">Use your MSU email to continue.</p>
          </div>

          {error && (
            <div className="signin-alert" role="alert" aria-live="polite">
              {error}
            </div>
          )}

          <form className="signin-form" onSubmit={handleLogin}>
            <div className="signin-field">
              <label className="signin-label" htmlFor="login-email">Email address</label>
              <div className="signin-control">
                <MailIcon />
                <input
                  id="login-email"
                  className="signin-input"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="name@msumain.edu.ph"
                  autoComplete="email"
                  required
                />
              </div>
            </div>

            <div className="signin-field">
              <label className="signin-label" htmlFor="login-password">Password</label>
              <div className="signin-control">
                <LockIcon />
                <input
                  id="login-password"
                  className="signin-input signin-input--password"
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  onKeyDown={updateCapsLock}
                  onKeyUp={updateCapsLock}
                  onBlur={() => setCapsLockOn(false)}
                  placeholder="Enter your password"
                  autoComplete="current-password"
                  aria-describedby={capsLockOn ? 'login-caps-lock' : undefined}
                  required
                />
                <button
                  type="button"
                  className="signin-reveal"
                  onClick={() => setShowPassword((value) => !value)}
                  aria-pressed={showPassword}
                  aria-controls="login-password"
                >
                  {showPassword ? 'Hide' : 'Show'}
                </button>
              </div>
              {capsLockOn && (
                <p id="login-caps-lock" className="signin-hint" aria-live="polite">
                  Caps Lock is on.
                </p>
              )}
            </div>

            <div className="signin-row">
              <label className="signin-check" htmlFor="login-keep-signed-in">
                <input
                  id="login-keep-signed-in"
                  type="checkbox"
                  checked={keepSignedIn}
                  onChange={(e) => setKeepSignedIn(e.target.checked)}
                />
                <span>Keep me signed in</span>
              </label>

              <Link to="/reset-password" className="signin-link">Forgot password?</Link>
            </div>

            <button className="signin-submit" type="submit" disabled={submitting}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </button>

            <div className="signin-note">
              New accounts are reviewed by the Program Leader before first use.
            </div>
          </form>
        </main>
      </div>
    </div>
  );
}
