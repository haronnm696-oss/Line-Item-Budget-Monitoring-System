import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeEmail } from '../api/auth';
import { isValidLoginEmail } from '../lib/authValidation';
import {
  AuthAlert,
  AuthField,
  AuthIcon,
  AuthNote,
  AuthPage,
  AuthPillButton,
} from '../components/auth/AuthPage';

const BRAND_FORM = {
  title: 'Reset your password',
  lead: 'Enter your MSU email and we will send you a secure link to choose a new password.',
  cta: { to: '/login', label: 'Back to sign in' },
};

const BRAND_SENT = {
  title: 'Reset your password',
  lead: 'Open the email we sent and follow the link to choose a new password.',
  cta: { to: '/login', label: 'Back to sign in' },
};

function formatCountdown(seconds) {
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return `${minutes}:${String(remainder).padStart(2, '0')}`;
}

export default function ForgotPassword() {
  const navigate = useNavigate();
  const { requestPasswordReset } = useAuth();

  const [email, setEmail] = useState('');
  const [submittedEmail, setSubmittedEmail] = useState('');
  const [view, setView] = useState('form');
  const [emailError, setEmailError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [seconds, setSeconds] = useState(0);
  const [resending, setResending] = useState(false);

  useEffect(() => {
    if (seconds <= 0) return undefined;

    const timer = window.setTimeout(() => {
      setSeconds((value) => Math.max(0, value - 1));
    }, 1000);

    return () => window.clearTimeout(timer);
  }, [seconds]);

  async function handleSubmit(event) {
    event.preventDefault();
    if (submitting) return;
    setEmailError('');
    setError('');

    const cleanEmail = normalizeEmail(email);
    if (!isValidLoginEmail(cleanEmail)) {
      setEmailError('Enter a valid MSU-Main or Gmail email address.');
      return;
    }

    setSubmitting(true);

    try {
      await requestPasswordReset(cleanEmail);
      setSubmittedEmail(cleanEmail);
      setView('sent');
      setSeconds(60);
    } catch (err) {
      if (err?.errors?.email) {
        const message = Array.isArray(err.errors.email) ? err.errors.email[0] : err.errors.email;
        setEmailError(message || 'Enter a valid email address.');
      } else {
        setError(err?.message || 'We could not send the reset link. Check your connection and try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  async function handleResend() {
    if (seconds > 0 || resending) return;

    setError('');
    setResending(true);

    try {
      await requestPasswordReset(submittedEmail);
      setSeconds(60);
    } catch (err) {
      setError(err?.message || 'We could not resend the link. Check your connection and try again.');
    } finally {
      setResending(false);
    }
  }

  if (view === 'sent') {
    return (
      <AuthPage brand={BRAND_SENT}>
        <div className="signin-state" role="status" aria-live="polite">
          <div className="signin-state__icon signin-state__icon--success" aria-hidden="true">
            <AuthIcon name="mail" className="signin-state__glyph" />
          </div>
          <h1 className="signin-state__title">Check your email</h1>
          <p className="signin-state__text">
            If an account exists for <strong>{submittedEmail}</strong>, we sent a link to reset your password. The link expires in 60 minutes.
          </p>

          {error && <AuthAlert>{error}</AuthAlert>}

          <AuthPillButton type="button" onClick={() => navigate('/login', { replace: true })}>
            Back to sign in
          </AuthPillButton>

          <button
            type="button"
            className="signin-secondary"
            onClick={handleResend}
            disabled={seconds > 0 || resending}
          >
            {seconds > 0
              ? `Resend in ${formatCountdown(seconds)}`
              : (resending ? 'Sending…' : 'Resend link')}
          </button>

          <p className="signin-state__hint">Did not get it? Check your spam folder.</p>
        </div>
      </AuthPage>
    );
  }

  return (
    <AuthPage brand={BRAND_FORM}>
      <div className="signin-head">
        <h1 className="signin-title">Forgot password?</h1>
        <p className="signin-subtitle">
          Enter your MSU email. If it matches an account, we will send you a link to reset your password.
        </p>
      </div>

      {error && <AuthAlert>{error}</AuthAlert>}

      <form className="signin-form" onSubmit={handleSubmit} noValidate>
        <AuthField id="forgot-email" label="Email address" icon="mail" error={emailError}>
          <input
            id="forgot-email"
            className={`signin-input${emailError ? ' is-invalid' : ''}`}
            type="email"
            value={email}
            onChange={(event) => {
              setEmail(event.target.value);
              if (emailError) setEmailError('');
            }}
            placeholder="name@s.msumain.edu.ph"
            autoComplete="email"
            aria-invalid={Boolean(emailError)}
            aria-describedby={emailError ? 'forgot-email-error' : undefined}
            required
          />
        </AuthField>

        <AuthPillButton type="submit" disabled={submitting}>
          {submitting ? 'Sending…' : 'Send reset link'}
        </AuthPillButton>

        <p className="signin-switch">
          <Link to="/login" className="signin-link">Back to sign in</Link>
        </p>

        <AuthNote>
          For your security, we show the same message whether or not the email is registered. The link expires in 60 minutes.
        </AuthNote>
      </form>
    </AuthPage>
  );
}
