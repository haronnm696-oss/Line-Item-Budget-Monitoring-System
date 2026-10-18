import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { isValidEmail, normalizeEmail } from '../api/auth';

export default function ResetPassword() {
  const [searchParams] = useSearchParams();
  const email = searchParams.get('email') || '';
  const token = searchParams.get('token') || '';

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [status, setStatus] = useState(''); // '' | 'done'
  const [submitting, setSubmitting] = useState(false);

  const { resetPassword } = useAuth();
  const navigate = useNavigate();

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setStatus('');
    if (!isValidEmail(normalizeEmail(email))) {
      setError('Invalid or missing email in reset link.');
      return;
    }
    if (!token) {
      setError('Invalid or missing reset token.');
      return;
    }
    if (!password || password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    if (password !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }
    setSubmitting(true);
    try {
      await resetPassword({ email: normalizeEmail(email), token, password });
      setStatus('done');
    } catch (err) {
      setError(err.message || 'Reset failed. Please request a new link.');
    } finally {
      setSubmitting(false);
    }
  }

  if (status === 'done') {
    return (
      <div className="login-screen">
        <div className="login-card">
          <div className="login-brand">
            <div className="seal">MSU</div>
            <h2>LIB Monitoring System</h2>
            <p>Password reset</p>
          </div>
          <div className="login-body">
            <div className="banner ok">Your password has been reset. You can now sign in.</div>
            <div className="login-foot">
              <button
                type="button"
                className="link-btn"
                onClick={() => navigate('/login', { replace: true })}
              >
                Sign in
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="login-screen">
      <div className="login-card">
        <div className="login-brand">
          <div className="seal">MSU</div>
          <h2>LIB Monitoring System</h2>
          <p>Reset your password</p>
        </div>
        <form className="login-body" onSubmit={handleSubmit} noValidate>
          <div className="field">
            <label htmlFor="email">Email Address</label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              value={email}
              readOnly
            />
            {email && (
              <p style={{ fontSize: '11px', color: 'var(--muted)', marginTop: '4px' }}>
                Reset link sent to {email}
              </p>
            )}
          </div>
          <div className="field">
            <label htmlFor="pw">New Password</label>
            <input
              id="pw"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            {password && password.length < 8 && (
              <div className="role-preview err">Password must be at least 8 characters.</div>
            )}
          </div>
          <div className="field">
            <label htmlFor="pw2">Confirm Password</label>
            <input
              id="pw2"
              type="password"
              autoComplete="new-password"
              placeholder="••••••••"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
            />
            {confirmPassword && confirmPassword !== password && (
              <div className="role-preview err">Passwords do not match.</div>
            )}
          </div>
          {error && <div className="banner over">{error}</div>}
          <button className="btn primary" type="submit" disabled={submitting}>
            {submitting && <span className="spin" />}Reset password
          </button>
          <div className="login-foot">
            Remember your password?{' '}
            <button
              type="button"
              className="link-btn"
              onClick={() => navigate('/login', { replace: true })}
            >
              Sign in
            </button>
            &nbsp;|&nbsp;
            <button
              type="button"
              className="link-btn"
              onClick={() => navigate('/login', { replace: true })}
            >
              Request a new link
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
