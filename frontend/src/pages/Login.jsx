import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { isValidEmail, normalizeEmail } from '../api/auth';

const ROLE_OPTIONS = [
  { label: 'Program Leader', slug: 'program-leader' },
  { label: 'Project Leader', slug: 'project-leader' },
  { label: 'Research Assistant', slug: 'research-assistant' },
  { label: 'Researcher', slug: 'researcher' },
];

const DEMO_PASSWORD = 'Password123!';

const DEMO_ACCOUNTS = [
  { role: 'Program Leader', email: 'ilham.gaml@msumain.edu.ph', slug: 'program-leader' },
  { role: 'Project Leader', email: 'project.leader@msumain.edu.ph', slug: 'project-leader' },
  { role: 'Research Assistant', email: 'ra.sample@msumain.edu.ph', slug: 'research-assistant' },
  { role: 'Researcher', email: 'researcher.sample@msumain.edu.ph', slug: 'researcher' },
];

export default function Login() {
  // ---------- Login state ----------
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // ---------- Registration state ----------
  const [regName, setRegName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regRole, setRegRole] = useState('');
  const [regError, setRegError] = useState('');
  const [registering, setRegistering] = useState(false);

  // ---------- Forgot password state ----------
  const [forgotEmail, setForgotEmail] = useState('');
  const [forgotError, setForgotError] = useState('');
  const [forgotStatus, setForgotStatus] = useState(''); // '' | 'sent'
  const [resetting, setResetting] = useState(false);

  const [mode, setMode] = useState('login'); // 'login' | 'register' | 'forgot'

  const { signIn, signUp, requestPasswordReset } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  // ---------- Login ----------
  const cleanEmail = normalizeEmail(email);
  const emailOk = !cleanEmail || isValidEmail(cleanEmail);
  const passwordOk = !password || password.length >= 8;

  async function handleSubmit(e) {
    e.preventDefault();
    const clean = normalizeEmail(email);
    if (!isValidEmail(clean)) {
      setError('Enter a valid Gmail address.');
      return;
    }
    if (!password || password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      await signIn(clean, password);
      const from = location.state?.from?.pathname || '/dashboard';
      navigate(from, { replace: true });
    } catch (err) {
      setError(err.message || 'Invalid email or password.');
    } finally {
      setSubmitting(false);
    }
  }

  // ---------- Register ----------
  async function handleRegister(e) {
    e.preventDefault();
    const clean = normalizeEmail(regEmail);
    setRegError('');
    if (!regName) {
      setRegError('Full name is required.');
      return;
    }
    if (!isValidEmail(clean)) {
      setRegError('Enter a valid Gmail address.');
      return;
    }
    if (!regPassword || regPassword.length < 8) {
      setRegError('Password must be at least 8 characters.');
      return;
    }
    if (!regRole) {
      setRegError('Please select a role.');
      return;
    }
    setRegistering(true);
    try {
      await signUp({ name: regName, email: clean, password: regPassword, role: regRole });
      const from = location.state?.from?.pathname || '/dashboard';
      navigate(from, { replace: true });
    } catch (err) {
      setRegError(err.message || 'Registration failed. Please try again.');
    } finally {
      setRegistering(false);
    }
  }

  // ---------- Forgot password ----------
  async function handleForgot(e) {
    e.preventDefault();
    const clean = normalizeEmail(forgotEmail);
    setForgotError('');
    setForgotStatus('');
    if (!isValidEmail(clean)) {
      setForgotError('Enter a valid Gmail address.');
      return;
    }
    setResetting(true);
    try {
      await requestPasswordReset(clean);
      setForgotStatus('sent');
    } catch (err) {
      setForgotError(err.message || 'Unable to process your request. Please try again.');
    } finally {
      setResetting(false);
    }
  }

  // ---------- Demo credentials ----------
  function fillDemo(email, password) {
    setEmail(email);
    setPassword(password);
    setError('');
  }

  const linkBtn = (onClick, children) => (
    <button type="button" onClick={onClick} className="link-btn" aria-label={children}>
      {children}
    </button>
  );

  return (
    <div className="login-screen">
      <div className="login-card">
        <div className="login-brand">
          <div className="seal">MSU</div>
          <h2>LIB Monitoring System</h2>
          <p>Externally Funded Research Projects · Mindanao State University–Main Campus</p>
        </div>

        {/* ---------- LOGIN ---------- */}
        {mode === 'login' && (
          <>
            <form className="login-body" onSubmit={handleSubmit} noValidate>
              <div className="field">
                <label htmlFor="email">Gmail</label>
                <input
                  id="email"
                  type="email"
                  autoComplete="username"
                  placeholder="e.g. project.leader@gmail.com or ilham.gaml@msumain.edu.ph"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  aria-invalid={cleanEmail && !emailOk ? 'true' : undefined}
                />
                {cleanEmail && !emailOk && (
                  <div className="role-preview err">Enter a valid email address.</div>
                )}
              </div>
              <div className="field">
                <label htmlFor="pw">Password</label>
                <div style={{ position: 'relative' }}>
                  <input
                    id="pw"
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    placeholder="••••••••"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    aria-invalid={password && !passwordOk ? 'true' : undefined}
                  />
                  <button
                    type="button"
                    className="icon-btn"
                    style={{ position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)' }}
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                  >
                    {showPassword ? '🙈' : '👁'}
                  </button>
                </div>
                {password && !passwordOk && (
                  <div className="role-preview err">Password must be at least 8 characters.</div>
                )}
              </div>
              {error && <div className="banner over">{error}</div>}
              <button className="btn primary" type="submit" disabled={submitting}>
                {submitting && <span className="spin" />}Sign in
              </button>
              <div className="login-foot">
                Sign in with your Gmail. You are sent straight to your role&apos;s dashboard.
                <br />
                Don&apos;t have an account?{' '}
                {linkBtn(() => setMode('register'), 'Sign Up')}
                &nbsp;|&nbsp;
                {linkBtn(() => setMode('forgot'), 'Forgot Password?')}
              </div>
            </form>

            {/* ---------- DEMO CREDENTIALS ---------- */}
            <div className="demo-credentials">
              <h3>Demo Credentials</h3>
              <p className="demo-hint">One-click to fill the login form for testing. Default password: <b>{DEMO_PASSWORD}</b></p>
              <div className="demo-grid">
                {DEMO_ACCOUNTS.map((acct) => (
                  <button
                    key={acct.email}
                    type="button"
                    className="btn demo-btn"
                    onClick={() => fillDemo(acct.email, DEMO_PASSWORD)}
                  >
                    <span className="demo-role">{acct.role}</span>
                    <span className="demo-email">{acct.email}</span>
                  </button>
                ))}
              </div>
            </div>
          </>
        )}

        {/* ---------- REGISTER ---------- */}
        {mode === 'register' && (
          <form className="login-body" onSubmit={handleRegister} noValidate>
            <div className="field">
              <label htmlFor="reg-name">Full Name</label>
              <input
                id="reg-name"
                type="text"
                autoComplete="name"
                placeholder="e.g. Juan Dela Cruz"
                value={regName}
                onChange={(e) => setRegName(e.target.value)}
              />
            </div>
            <div className="field">
              <label htmlFor="reg-email">Gmail Address</label>
              <input
                id="reg-email"
                type="email"
                autoComplete="email"
                placeholder="e.g. jane.doe@gmail.com"
                value={regEmail}
                onChange={(e) => setRegEmail(e.target.value)}
              />
            </div>
            <div className="field">
              <label htmlFor="reg-pw">Password</label>
              <input
                id="reg-pw"
                type="password"
                autoComplete="new-password"
                placeholder="••••••••"
                value={regPassword}
                onChange={(e) => setRegPassword(e.target.value)}
              />
              {regPassword && regPassword.length < 8 && (
                <div className="role-preview err">Password must be at least 8 characters.</div>
              )}
            </div>
            <div className="field">
              <label htmlFor="reg-role">Role</label>
              <select
                id="reg-role"
                value={regRole}
                onChange={(e) => setRegRole(e.target.value)}
                autoComplete="off"
              >
                <option value="">Select a role</option>
                {ROLE_OPTIONS.map((r) => (
                  <option key={r.slug} value={r.slug}>{r.label}</option>
                ))}
              </select>
            </div>
            {regError && <div className="banner over">{regError}</div>}
            <button className="btn primary" type="submit" disabled={registering}>
              {registering && <span className="spin" />}Create account
            </button>
            <div className="login-foot">
              Already have an account?{' '}
              {linkBtn(() => setMode('login'), 'Sign in')}
            </div>
          </form>
        )}

        {/* ---------- FORGOT PASSWORD ---------- */}
        {mode === 'forgot' && (
          <form className="login-body" onSubmit={handleForgot} noValidate>
            <div className="field">
              <label htmlFor="forgot-email">Registered Gmail Address</label>
              <input
                id="forgot-email"
                type="email"
                autoComplete="email"
                placeholder="e.g. jane.doe@gmail.com"
                value={forgotEmail}
                onChange={(e) => setForgotEmail(e.target.value)}
              />
            </div>
            {forgotError && <div className="banner over">{forgotError}</div>}
            {forgotStatus === 'sent' && (
              <div className="banner ok">
                If an account exists for that address, a password reset link has been sent.
              </div>
            )}
            <button className="btn primary" type="submit" disabled={resetting}>
              {resetting && <span className="spin" />}Send reset link
            </button>
            <div className="login-foot">
              Remember your password?{' '}
              {linkBtn(() => setMode('login'), 'Sign in')}
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
