import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeEmail } from '../api/auth';
import { isEmailFormat, passwordRules } from '../lib/authValidation';
import {
  AuthAlert,
  AuthField,
  AuthIcon,
  AuthPage,
  AuthPasswordFeedback,
  AuthPillButton,
  CheckIcon,
} from '../components/auth/AuthPage';

const BRAND_FORM = {
  title: 'Choose a new password',
  lead: 'Pick a strong password that you do not use anywhere else.',
  cta: { to: '/login', label: 'Back to sign in' },
};

const BRAND_SUCCESS = {
  title: 'All set',
  lead: 'Your password has been updated.',
  cta: { to: '/login', label: 'Back to sign in' },
};

const BRAND_EXPIRED = {
  title: 'Reset your password',
  lead: 'Reset links work once and expire after 60 minutes.',
  cta: { to: '/login', label: 'Back to sign in' },
};

export default function ResetPassword() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { resetPassword } = useAuth();

  const email = normalizeEmail(searchParams.get('email') || '');
  const token = searchParams.get('token') || '';
  const linkIsUsable = Boolean(token) && isEmailFormat(email);

  const [view, setView] = useState(linkIsUsable ? 'form' : 'expired');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPasswords, setShowPasswords] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [confirmError, setConfirmError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const rules = useMemo(
    () => passwordRules(password, '', '', email),
    [password, email],
  );
  const allRulesMet = rules.every((rule) => rule.met);
  const showPasswordFeedback = password.length > 0;

  async function handleSubmit(event) {
    event.preventDefault();
    if (submitting) return;
    setError('');
    setPasswordError('');
    setConfirmError('');

    if (!linkIsUsable) {
      setView('expired');
      return;
    }

    if (!allRulesMet) {
      setPasswordError('Your password must meet all four rules below.');
      return;
    }

    if (!confirmPassword) {
      setConfirmError('Confirm your new password.');
      return;
    }

    if (password !== confirmPassword) {
      setConfirmError('Passwords do not match.');
      return;
    }

    setSubmitting(true);

    try {
      await resetPassword({
        email,
        token,
        password,
        passwordConfirmation: confirmPassword,
      });
      setView('success');
    } catch (err) {
      const errors = err?.errors || {};

      if (errors.token || errors.email) {
        setView('expired');
        return;
      }

      if (errors.password) {
        const message = Array.isArray(errors.password) ? errors.password[0] : errors.password;

        if (/confirmation/i.test(String(message || ''))) {
          setConfirmError('Passwords do not match.');
        } else {
          setPasswordError(message || 'Your password must meet all four rules below.');
        }
        return;
      }

      setError(err?.message || 'We could not reset your password. Please request a new link.');
    } finally {
      setSubmitting(false);
    }
  }

  if (view === 'success') {
    return (
      <AuthPage brand={BRAND_SUCCESS}>
        <div className="signin-state" role="status" aria-live="polite">
          <div className="signin-state__icon signin-state__icon--success" aria-hidden="true">
            <CheckIcon className="signin-state__glyph" />
          </div>
          <h1 className="signin-state__title">Password updated</h1>
          <p className="signin-state__text">
            You can now sign in with your new password. For your security, you were signed out on all other devices.
          </p>
          <AuthPillButton type="button" onClick={() => navigate('/login', { replace: true })}>
            Sign in
          </AuthPillButton>
        </div>
      </AuthPage>
    );
  }

  if (view === 'expired') {
    return (
      <AuthPage brand={BRAND_EXPIRED}>
        <div className="signin-state" role="alert">
          <div className="signin-state__icon signin-state__icon--warning" aria-hidden="true">
            <AuthIcon name="alert" className="signin-state__glyph" />
          </div>
          <h1 className="signin-state__title">This link has expired</h1>
          <p className="signin-state__text">
            Reset links can be used only once and expire after 60 minutes. Request a new one to continue.
          </p>
          <AuthPillButton type="button" onClick={() => navigate('/forgot-password', { replace: true })}>
            Request a new link
          </AuthPillButton>
          <p className="signin-switch">
            <Link to="/login" className="signin-link">Back to sign in</Link>
          </p>
        </div>
      </AuthPage>
    );
  }

  return (
    <AuthPage brand={BRAND_FORM}>
      <div className="signin-head">
        <h1 className="signin-title">Choose a new password</h1>
        <p className="signin-subtitle">
          Create a new password for <strong>{email}</strong>.
        </p>
      </div>

      {error && <AuthAlert>{error}</AuthAlert>}

      <form className="signin-form" onSubmit={handleSubmit} noValidate>
        <AuthField id="reset-password" label="New password" icon="lock" error={passwordError}>
          <input
            id="reset-password"
            className={`signin-input signin-input--password${passwordError ? ' is-invalid' : ''}`}
            type={showPasswords ? 'text' : 'password'}
            value={password}
            onChange={(event) => {
              setPassword(event.target.value);
              if (passwordError) setPasswordError('');
            }}
            placeholder="Enter a new password"
            autoComplete="new-password"
            aria-invalid={Boolean(passwordError)}
            aria-describedby={[
              passwordError ? 'reset-password-error' : null,
              showPasswordFeedback ? 'reset-password-rules' : null,
            ].filter(Boolean).join(' ') || undefined}
            required
          />
          <button
            type="button"
            className="signin-reveal"
            onClick={() => setShowPasswords((value) => !value)}
            aria-pressed={showPasswords}
            aria-controls="reset-password reset-confirm-password"
            aria-label={showPasswords ? 'Hide both passwords' : 'Show both passwords'}
          >
            {showPasswords ? 'Hide' : 'Show'}
          </button>
        </AuthField>

        <AuthField
          id="reset-confirm-password"
          label="Confirm new password"
          icon="lock"
          error={confirmError}
        >
          <input
            id="reset-confirm-password"
            className={`signin-input${confirmError ? ' is-invalid' : ''}`}
            type={showPasswords ? 'text' : 'password'}
            value={confirmPassword}
            onChange={(event) => {
              setConfirmPassword(event.target.value);
              if (confirmError) setConfirmError('');
            }}
            onBlur={() => {
              if (confirmPassword && confirmPassword !== password) {
                setConfirmError('Passwords do not match.');
              }
            }}
            placeholder="Re-enter your new password"
            autoComplete="new-password"
            aria-invalid={Boolean(confirmError)}
            aria-describedby={confirmError ? 'reset-confirm-password-error' : undefined}
            required
          />
        </AuthField>

        {showPasswordFeedback && <AuthPasswordFeedback rules={rules} id="reset-password-rules" />}

        <AuthPillButton type="submit" disabled={submitting}>
          {submitting ? 'Resetting…' : 'Reset password'}
        </AuthPillButton>

        <p className="signin-switch">
          <Link to="/login" className="signin-link">Back to sign in</Link>
        </p>
      </form>
    </AuthPage>
  );
}
