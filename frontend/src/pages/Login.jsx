import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ROUTE_BY_ROLE } from './Dashboard';
import { useAuth } from '../context/AuthContext';
import {
  AuthAlert,
  AuthField,
  AuthNote,
  AuthPage,
  AuthPillButton,
} from '../components/auth/AuthPage';

function getDashboardPath(roleCode) {
  return ROUTE_BY_ROLE[roleCode] || '/dashboard';
}

const BRAND = {
  title: 'Welcome back',
  lead: 'Monitor externally funded research budgets at Mindanao State University – Main Campus.',
  cta: { to: '/register', label: 'Request access' },
};

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
    <AuthPage brand={BRAND}>
      <div className="signin-head">
        <h1 className="signin-title">Sign in</h1>
        <p className="signin-subtitle">Use your MSU email to continue.</p>
      </div>

      {error && <AuthAlert>{error}</AuthAlert>}

      <form className="signin-form" onSubmit={handleLogin}>
        <AuthField id="login-email" label="Email address" icon="mail">
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
        </AuthField>

        <AuthField
          id="login-password"
          label="Password"
          icon="lock"
          hint={capsLockOn ? 'Caps Lock is on.' : ''}
          hintId="login-caps-lock"
          hintLive
        >
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
        </AuthField>

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

          <Link to="/forgot-password" className="signin-link">Forgot password?</Link>
        </div>

        <AuthPillButton type="submit" disabled={submitting}>
          {submitting ? 'Signing in…' : 'Sign in'}
        </AuthPillButton>

        <AuthNote>
          New accounts are reviewed by the Program Leader before first use.
        </AuthNote>
      </form>
    </AuthPage>
  );
}
