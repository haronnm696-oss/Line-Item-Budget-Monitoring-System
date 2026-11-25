import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { normalizeEmail } from '../api/auth';
import { isValidLoginEmail, passwordRules } from '../lib/authValidation';
import {
  CODE_EXPIRED_MESSAGE,
  RESET_DONE_NOTICE,
  RESET_TOKEN_TTL_SECONDS,
  SESSION_EXPIRED_MESSAGE,
  STEPS,
  codeSentDeadlines,
  codeValue,
  emptyCode,
  formatCountdown,
  isCodeComplete,
  resendLabel,
  resetFailure,
  secondsUntil,
  verifyFailure,
} from '../lib/passwordResetFlow';
import {
  AuthAlert,
  AuthField,
  AuthHead,
  AuthPage,
  AuthPasswordFeedback,
  AuthPillButton,
} from '../components/auth/AuthPage';
import CodeInput from '../components/auth/CodeInput';
import OrbitBackground from '../components/OrbitBackground';

const BRAND = {
  cta: { to: '/login', label: 'Back to sign in' },
};

const EYEBROW = 'Account recovery';

/** "jane.doe@gmail.com" -> "ja***@gmail.com", for display only. */
function maskEmail(value) {
  const [local, domain] = String(value || '').split('@');
  if (!domain) return value;
  return `${local.slice(0, local.length > 2 ? 2 : 1)}***@${domain}`;
}

/** Re-renders every second while `active`, for the countdowns. */
function useNow(active) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!active) return undefined;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [active]);
  return now;
}

export default function ForgotPassword() {
  const navigate = useNavigate();
  const { requestPasswordReset, verifyResetCode, resetPassword } = useAuth();

  const [step, setStep] = useState(STEPS.EMAIL);
  const [email, setEmail] = useState('');
  const [submittedEmail, setSubmittedEmail] = useState('');
  const [emailError, setEmailError] = useState('');
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Step 2
  const [digits, setDigits] = useState(emptyCode);
  const [codeError, setCodeError] = useState('');
  const [deadlines, setDeadlines] = useState({ codeExpiresAt: 0, resendAt: 0 });
  const [resending, setResending] = useState(false);

  // Step 3
  const [resetToken, setResetToken] = useState('');
  const [tokenExpiresAt, setTokenExpiresAt] = useState(0);
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPasswords, setShowPasswords] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [confirmError, setConfirmError] = useState('');

  const now = useNow(step === STEPS.CODE);
  const codeSecondsLeft = secondsUntil(deadlines.codeExpiresAt, now);
  const resendSecondsLeft = secondsUntil(deadlines.resendAt, now);
  const codeExpired = step === STEPS.CODE && deadlines.codeExpiresAt > 0 && codeSecondsLeft === 0;

  const rules = useMemo(() => passwordRules(password, '', '', submittedEmail), [password, submittedEmail]);
  const allRulesMet = rules.every((rule) => rule.met);

  function startOver(message = '') {
    setStep(STEPS.EMAIL);
    setDigits(emptyCode());
    setCodeError('');
    setInfo('');
    setResetToken('');
    setTokenExpiresAt(0);
    setPassword('');
    setConfirmPassword('');
    setPasswordError('');
    setConfirmError('');
    setError(message);
  }

  function throttled(err) {
    const retry = Number(err?.retryAfter);
    if (err?.status === 429 && retry > 0) {
      setDeadlines((current) => ({ ...current, resendAt: Date.now() + retry * 1000 }));
    }
  }

  // Step 1: request a code.
  async function handleEmailSubmit(event) {
    event.preventDefault();
    if (submitting) return;
    setEmailError('');
    setError('');

    const cleanEmail = normalizeEmail(email);
    if (!isValidLoginEmail(cleanEmail)) {
      setEmailError('Enter a valid email address.');
      return;
    }

    setSubmitting(true);
    try {
      const response = await requestPasswordReset(cleanEmail);
      setSubmittedEmail(cleanEmail);
      setDeadlines(codeSentDeadlines(Date.now(), response));
      setDigits(emptyCode());
      setCodeError('');
      setInfo('');
      setStep(STEPS.CODE);
    } catch (err) {
      if (err?.errors?.email) {
        const message = Array.isArray(err.errors.email) ? err.errors.email[0] : err.errors.email;
        setEmailError(message || 'Enter a valid email address.');
      } else {
        setError(err?.message || 'We could not send the code. Check your connection and try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  // Step 2: check the code.
  async function verify(code) {
    if (submitting) return;
    setError('');
    setInfo('');
    setCodeError('');

    if (codeExpired) {
      setCodeError(CODE_EXPIRED_MESSAGE);
      return;
    }
    if (!/^\d{6}$/.test(code)) {
      setCodeError('Enter the 6-digit code from the email.');
      return;
    }

    setSubmitting(true);
    try {
      const response = await verifyResetCode({ email: submittedEmail, code });
      const ttl = Number(response?.expires_in) > 0 ? Number(response.expires_in) : RESET_TOKEN_TTL_SECONDS;
      setResetToken(response.reset_token);
      setTokenExpiresAt(Date.now() + ttl * 1000);
      setStep(STEPS.PASSWORD);
    } catch (err) {
      setCodeError(verifyFailure(err));
      setDigits(emptyCode());
      document.getElementById('reset-code')?.focus();
    } finally {
      setSubmitting(false);
    }
  }

  async function handleResend() {
    if (resendSecondsLeft > 0 || resending) return;
    setError('');
    setInfo('');
    setCodeError('');
    setResending(true);
    try {
      const response = await requestPasswordReset(submittedEmail);
      setDeadlines(codeSentDeadlines(Date.now(), response));
      setDigits(emptyCode());
      setInfo(`We sent a new code to ${submittedEmail}. The previous code no longer works.`);
      document.getElementById('reset-code')?.focus();
    } catch (err) {
      throttled(err);
      setError(err?.message || 'We could not resend the code. Check your connection and try again.');
    } finally {
      setResending(false);
    }
  }

  // Step 3: set the new password.
  async function handlePasswordSubmit(event) {
    event.preventDefault();
    if (submitting) return;
    setError('');
    setPasswordError('');
    setConfirmError('');

    if (!resetToken || secondsUntil(tokenExpiresAt) === 0) {
      startOver(SESSION_EXPIRED_MESSAGE);
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
        email: submittedEmail,
        resetToken,
        password,
        passwordConfirmation: confirmPassword,
      });
      navigate('/login', { replace: true, state: { notice: RESET_DONE_NOTICE } });
    } catch (err) {
      const failure = resetFailure(err);
      if (failure.step === STEPS.EMAIL) {
        startOver(failure.error);
        return;
      }
      if (failure.passwordError) setPasswordError(failure.passwordError);
      if (failure.confirmError) setConfirmError(failure.confirmError);
      if (failure.error) setError(failure.error);
    } finally {
      setSubmitting(false);
    }
  }

  if (step === STEPS.CODE) {
    const describedBy = ['reset-code-status', codeError ? 'reset-code-error' : null].filter(Boolean).join(' ');

    return (
      <AuthPage brand={BRAND} backdrop={<OrbitBackground />}>
        <AuthHead eyebrow={EYEBROW} title="Check your email">
          We sent a 6-digit code to <strong>{maskEmail(submittedEmail)}</strong>. It expires in 10 minutes.
        </AuthHead>

        {error && <AuthAlert>{error}</AuthAlert>}
        {info && <AuthAlert tone="success">{info}</AuthAlert>}

        <form
          className="signin-form"
          noValidate
          onSubmit={(event) => {
            event.preventDefault();
            verify(codeValue(digits));
          }}
        >
          <div className="signin-field">
            <label className="signin-label" id="reset-code-label" htmlFor="reset-code">Enter code</label>
            <CodeInput
              id="reset-code"
              digits={digits}
              onChange={(next) => {
                setDigits(next);
                if (codeError) setCodeError('');
              }}
              onComplete={(next) => verify(codeValue(next))}
              disabled={submitting}
              invalid={Boolean(codeError)}
              describedBy={describedBy}
            />
            {codeError && (
              <p id="reset-code-error" className="signin-error" aria-live="polite">{codeError}</p>
            )}
            <p id="reset-code-status" className={`signin-hint signin-code__timer${codeExpired ? ' is-expired' : ''}`}>
              {codeExpired
                ? 'Expired. Request a new code.'
                : `Code expires in ${formatCountdown(codeSecondsLeft)}`}
            </p>
            <div className="signin-row signin-code__help">
              <span>Didn&apos;t get it? Check spam.</span>
              <button
                type="button"
                className="signin-link signin-link--button signin-resend"
                onClick={handleResend}
                disabled={resendSecondsLeft > 0 || resending}
              >
                {resendLabel(resendSecondsLeft, resending)}
              </button>
            </div>
          </div>

          <AuthPillButton type="submit" disabled={submitting || codeExpired || !isCodeComplete(digits)}>
            {submitting ? 'Checking…' : 'Verify code'}
          </AuthPillButton>

          <p className="signin-switch">
            <button type="button" className="signin-link signin-link--button" onClick={() => startOver()}>
              Use a different email
            </button>
          </p>
        </form>
      </AuthPage>
    );
  }

  if (step === STEPS.PASSWORD) {
    return (
      <AuthPage brand={BRAND} backdrop={<OrbitBackground />}>
        <AuthHead eyebrow={EYEBROW} title="Set a new password">
          Choose a new password for <strong>{maskEmail(submittedEmail)}</strong>.
        </AuthHead>

        {error && <AuthAlert>{error}</AuthAlert>}

        <form className="signin-form" onSubmit={handlePasswordSubmit} noValidate>
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
                'reset-password-rules',
              ].filter(Boolean).join(' ')}
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

          <AuthPasswordFeedback rules={rules} id="reset-password-rules" variant="checklist" />

          <AuthField id="reset-confirm-password" label="Confirm new password" icon="lock" error={confirmError}>
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

          <AuthPillButton type="submit" disabled={submitting}>
            {submitting ? 'Resetting…' : 'Save new password'}
          </AuthPillButton>
        </form>
      </AuthPage>
    );
  }

  return (
    <AuthPage brand={BRAND} backdrop={<OrbitBackground />}>
      <AuthHead eyebrow={EYEBROW} title="Forgot your password?">
        Enter the email you use for LIB. We&apos;ll send you a 6-digit code to reset your password.
      </AuthHead>

      {error && <AuthAlert>{error}</AuthAlert>}

      <form className="signin-form" onSubmit={handleEmailSubmit} noValidate>
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
            placeholder="name@example.com"
            autoComplete="email"
            aria-invalid={Boolean(emailError)}
            aria-describedby={emailError ? 'forgot-email-error' : undefined}
            required
          />
        </AuthField>

        <AuthPillButton type="submit" disabled={submitting}>
          {submitting ? 'Sending…' : 'Send code'}
        </AuthPillButton>
      </form>
    </AuthPage>
  );
}
