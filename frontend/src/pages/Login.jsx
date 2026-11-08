import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ROUTE_BY_ROLE } from './Dashboard';
import { useAuth } from '../context/AuthContext';
import AuthShell from '../components/auth/AuthShell';

function getDashboardPath(roleCode) {
  return ROUTE_BY_ROLE[roleCode] || '/dashboard';
}

const CHECKLIST = [
  'Track budgets by line item',
  'Approve releases and realignments',
  'Generate funder-ready reports',
];

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
    <AuthShell
      title="Welcome back"
      lead="Monitor externally funded research budgets at Mindanao State University – Main Campus."
      items={CHECKLIST}
      maxWidth={420}
    >
      <div className="auth-form__heading">
        <h1 className="auth-form__title">Sign in</h1>
        <p className="auth-form__subtitle">Use your MSU email to continue.</p>
      </div>

      {error && <div className="auth-error" role="alert">{error}</div>}

      <form className="auth-form__body" onSubmit={handleLogin}>
        <div className="auth-field">
          <label className="auth-label" htmlFor="login-email">Email address</label>
          <input
            id="login-email"
            className="auth-input"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="name@msumain.edu.ph"
            autoComplete="email"
            required
          />
        </div>

        <div className="auth-field">
          <div className="auth-field__head">
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
              className="auth-affix-btn"
              onClick={() => setShowPassword((value) => !value)}
              aria-pressed={showPassword}
              aria-controls="login-password"
            >
              {showPassword ? 'Hide' : 'Show'}
            </button>
          </div>
          {capsLockOn && (
            <p id="login-caps-lock" className="auth-field__hint" aria-live="polite">
              Caps Lock is on.
            </p>
          )}
        </div>

        <label className="auth-checkbox" htmlFor="login-keep-signed-in">
          <input
            id="login-keep-signed-in"
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

      <div className="auth-note">
        New accounts are reviewed by the Program Leader before first use.
      </div>
    </AuthShell>
  );
}
