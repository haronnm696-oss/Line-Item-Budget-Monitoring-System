import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const COLLEGES = [
  { name: 'College of Computing and Information Sciences', departments: ['Computer Science', 'Information Technology', 'Data Science'] },
  { name: 'College of Business and Finance', departments: ['Finance', 'Accounting'] },
  { name: 'College of Social Sciences and Humanities', departments: ['Research', 'Education'] },
];

function getPasswordStrength(pw) {
  if (!pw) return 0;
  let score = 0;
  if (pw.length >= 8) score += 1;
  if (pw.length >= 12) score += 1;
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score += 1;
  if (/\d/.test(pw)) score += 1;
  if (/[^A-Za-z0-9]/.test(pw)) score += 1;
  return Math.min(score, 5);
}

const STRENGTH_LABELS = ['', 'Weak', 'Weak', 'Fair', 'Good', 'Strong'];

function isPersonalPassword(pw, firstName, lastName, email) {
  const lower = pw.toLowerCase();
  const first = (firstName || '').trim().toLowerCase();
  const last = (lastName || '').trim().toLowerCase();
  const emailLocal = (email || '').split('@')[0].toLowerCase();

  if (first && lower.includes(first)) return false;
  if (last && lower.includes(last)) return false;
  if (emailLocal && lower.includes(emailLocal)) return false;
  return true;
}

export default function Register() {
  const { signUp } = useAuth();

  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    contactNumber: '',
    employeeId: '',
    position: '',
    college: '',
    department: '',
    password: '',
    confirmPassword: '',
  });
  const [agree, setAgree] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const selectedCollege = COLLEGES.find((c) => c.name === formData.college);
  const departments = selectedCollege ? selectedCollege.departments : [];

  const strength = getPasswordStrength(formData.password);
  const strengthLabel = STRENGTH_LABELS[strength];

  const passwordChecks = [
    { label: '8+ characters', valid: formData.password.length >= 8 },
    { label: 'Contains a number', valid: /\d/.test(formData.password) },
    { label: 'Contains a symbol', valid: /[^A-Za-z0-9]/.test(formData.password) },
    { label: 'Not your name or email', valid: isPersonalPassword(formData.password, formData.firstName, formData.lastName, formData.email) },
  ];
  const passwordValid = formData.password.length >= 8 && /\d/.test(formData.password) && /[^A-Za-z0-9]/.test(formData.password) && passwordChecks[3].valid;
  const confirmValid = formData.confirmPassword.length > 0 && formData.confirmPassword === formData.password && passwordValid;

  const emailValid = /^[^@\s]+@msumain\.edu\.ph$/i.test(formData.email);
  const employeeIdValid = /^MSU-\d{5}$/.test(formData.employeeId.trim());

  function handleChange(e) {
    const { name, value } = e.target;
    setFormData((prev) => {
      const next = { ...prev, [name]: value };
      if (name === 'college') {
        next.department = '';
      }
      return next;
    });
  }

  async function handleRegister(e) {
    e?.preventDefault();

    if (!formData.firstName || !formData.lastName || !formData.email || !formData.password) {
      setError('Please fill in all required fields.');
      return;
    }
    if (formData.password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    if (formData.password !== formData.confirmPassword) {
      setError('Passwords do not match.');
      return;
    }
    if (!agree) {
      setError('Please accept the policy to continue.');
      return;
    }
    if (!employeeIdValid) {
      setError('Employee ID must be in format MSU-XXXXX.');
      return;
    }
    if (!emailValid) {
      setError('Please use your institutional email address.');
      return;
    }

    setSubmitting(true);
    setError('');

    try {
      const res = await signUp({
        first_name: formData.firstName,
        last_name: formData.lastName,
        email: formData.email,
        password: formData.password,
        employee_id: formData.employeeId,
        contact_number: formData.contactNumber || undefined,
        college: formData.college || undefined,
        department: formData.department || undefined,
        position: formData.position,
      });
      setSuccess(true);
    } catch (err) {
      setError(err?.message || 'Registration failed. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  if (success) {
    return (
      <div className="auth-screen">
        <div className="auth-card">
          <div className="auth-panel auth-panel--bg">
            <div className="auth-form-wrap">
              <div className="auth-form-card" style={{ textAlign: 'center', padding: '32px' }}>
                <div className="auth-badge" style={{ margin: '0 auto 20px', background: 'var(--ok)', borderColor: 'var(--ok)' }}>
                  &#10003;
                </div>
                <h2 className="auth-form-title">Registration submitted</h2>
                <p className="auth-form-sub" style={{ marginBottom: 24 }}>
                  Your account is pending approval. A Program Leader will review it and you will be emailed once approved.
                </p>
                <button className="auth-btn" type="button" onClick={() => setSuccess(false)}>
                  Back to sign in
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-screen">
      <div className="auth-card">
        <div className="auth-panel auth-panel--brand">
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 32 }}>
              <div className="auth-badge">MSU</div>
              <div className="auth-brand-name">LIB Monitoring System</div>
            </div>

            <h1 className="auth-title">Request access</h1>
            <div className="auth-underline" />

            <div className="auth-steps">
              <div className="auth-step">
                <span className="auth-step-number">1</span>
                <span>Register with your MSU email</span>
              </div>
              <div className="auth-step">
                <span className="auth-step-number">2</span>
                <span>The Program Leader reviews your request and assigns your role</span>
              </div>
              <div className="auth-step">
                <span className="auth-step-number">3</span>
                <span>Sign in once you are approved</span>
              </div>
            </div>
          </div>

          <div className="auth-footer">MSU–Main Campus · Authorized personnel only</div>
        </div>

        <div className="auth-panel auth-panel--bg">
          <div className="auth-form-wrap">
            <div className="auth-form-card">
              <h2 className="auth-form-title">Create account</h2>
              <p className="auth-form-sub">Fill in the details to request access.</p>

              {error && <div className="auth-error" role="alert">{error}</div>}

              <form onSubmit={handleRegister}>
                <div className="auth-section-title">Personal details</div>

                <div className="auth-grid-2">
                  <div className="auth-field">
                    <label className="auth-label" htmlFor="reg-first-name">First name</label>
                    <input
                      id="reg-first-name"
                      className="auth-input"
                      type="text"
                      name="firstName"
                      value={formData.firstName}
                      onChange={handleChange}
                      autoComplete="given-name"
                      required
                    />
                    {formData.firstName.trim().length > 0 && (
                      <div className="auth-check" style={{ marginTop: 6, color: 'var(--ok)' }}>
                        <span className="auth-check-icon">&#10003;</span>
                        <span>Valid</span>
                      </div>
                    )}
                  </div>
                  <div className="auth-field">
                    <label className="auth-label" htmlFor="reg-last-name">Last name</label>
                    <input
                      id="reg-last-name"
                      className="auth-input"
                      type="text"
                      name="lastName"
                      value={formData.lastName}
                      onChange={handleChange}
                      autoComplete="family-name"
                      required
                    />
                    {formData.lastName.trim().length > 0 && (
                      <div className="auth-check" style={{ marginTop: 6, color: 'var(--ok)' }}>
                        <span className="auth-check-icon">&#10003;</span>
                        <span>Valid</span>
                      </div>
                    )}
                  </div>
                </div>

                <div className="auth-grid-2">
                  <div className="auth-field">
                    <label className="auth-label" htmlFor="reg-email">Email address</label>
                    <input
                      id="reg-email"
                      className="auth-input"
                      type="email"
                      name="email"
                      value={formData.email}
                      onChange={handleChange}
                      autoComplete="email"
                      required
                    />
                    {formData.email && !emailValid && (
                      <div className="auth-error" style={{ marginTop: 6, marginBottom: 0 }}>Please use your institutional email address.</div>
                    )}
                    {formData.email && emailValid && (
                      <div className="auth-check" style={{ marginTop: 6, color: 'var(--ok)' }}>
                        <span className="auth-check-icon">&#10003;</span>
                        <span>Valid</span>
                      </div>
                    )}
                  </div>
                  <div className="auth-field">
                    <label className="auth-label" htmlFor="reg-contact">Contact number</label>
                    <input
                      id="reg-contact"
                      className="auth-input"
                      type="text"
                      name="contactNumber"
                      value={formData.contactNumber}
                      onChange={(e) => {
                        const cleaned = e.target.value.replace(/\D/g, '').slice(0, 11);
                        setFormData((prev) => ({ ...prev, contactNumber: cleaned }));
                      }}
                      maxLength={11}
                      autoComplete="tel"
                      required
                    />
                  </div>
                </div>

                <div className="auth-section-title">Employment</div>

                <div className="auth-field">
                  <label className="auth-label" htmlFor="reg-employee-id">Employee ID</label>
                  <input
                    id="reg-employee-id"
                    className="auth-input"
                    type="text"
                    name="employeeId"
                    value={formData.employeeId}
                    onChange={handleChange}
                    autoComplete="off"
                    required
                  />
                  {formData.employeeId && !employeeIdValid && (
                    <div className="auth-error" style={{ marginTop: 6, marginBottom: 0 }}>Employee ID must be in format MSU-XXXXX.</div>
                  )}
                  {formData.employeeId && employeeIdValid && (
                    <div className="auth-check" style={{ marginTop: 6, color: 'var(--ok)' }}>
                      <span className="auth-check-icon">&#10003;</span>
                      <span>Valid</span>
                    </div>
                  )}
                </div>

                <div className="auth-field">
                  <label className="auth-label" htmlFor="reg-position">Position</label>
                  <input
                    id="reg-position"
                    className="auth-input"
                    type="text"
                    name="position"
                    value={formData.position}
                    onChange={handleChange}
                    placeholder="e.g. Professor VI"
                    required
                  />
                </div>

                <div className="auth-grid-2">
                  <div className="auth-field">
                    <label className="auth-label" htmlFor="reg-college">College</label>
                    <select id="reg-college" className="auth-input" name="college" value={formData.college} onChange={handleChange}>
                      <option value="">Select a college</option>
                      {COLLEGES.map((c) => (
                        <option key={c.name} value={c.name}>{c.name}</option>
                      ))}
                    </select>
                  </div>
                  <div className="auth-field">
                    <label className="auth-label" htmlFor="reg-department">Department</label>
                    <select id="reg-department" className="auth-input" name="department" value={formData.department} onChange={handleChange} disabled={!formData.college}>
                      <option value="">{formData.college ? 'Select a department' : 'Select a college first'}</option>
                      {departments.map((d) => (
                        <option key={d} value={d}>{d}</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="auth-section-title">Security</div>

                <div className="auth-field">
                  <label className="auth-label" htmlFor="reg-password">Password</label>
                  <div className="auth-input-affix">
                    <input
                      id="reg-password"
                      className="auth-input"
                      type={showPassword ? 'text' : 'password'}
                      name="password"
                      value={formData.password}
                      onChange={handleChange}
                      autoComplete="new-password"
                      required
                    />
                    <button type="button" className="auth-affix-btn" onClick={() => setShowPassword((s) => !s)}>
                      {showPassword ? 'Hide' : 'Show'}
                    </button>
                  </div>
                  {formData.password && (
                    <>
                      <div style={{ height: 4, backgroundColor: 'var(--brand-surface)', borderRadius: 2, overflow: 'hidden', marginTop: 8 }}>
                        <div style={{ width: `${(strength / 5) * 100}%`, height: '4px', backgroundColor: strength >= 4 ? 'var(--ok)' : strength >= 3 ? 'var(--warn)' : 'var(--over)', borderRadius: 2 }} />
                      </div>
                      <div style={{ fontSize: 11, color: 'rgba(34,29,26,.72)', marginTop: 4 }}>Password strength: {strengthLabel}</div>
                    </>
                  )}
                  {passwordChecks.map((check) => (
                    <div className="auth-check" key={check.label} style={{ marginTop: 8 }}>
                      <span className="auth-check-icon" style={{ color: check.valid ? 'var(--ok)' : 'var(--gold)' }}>
                        {check.valid ? '&#10003;' : ''}
                      </span>
                      <span>{check.label}</span>
                    </div>
                  ))}
                </div>

                <div className="auth-field">
                  <label className="auth-label" htmlFor="reg-confirm-password">Confirm password</label>
                  <div className="auth-input-affix">
                    <input
                      id="reg-confirm-password"
                      className="auth-input"
                      type={showConfirmPassword ? 'text' : 'password'}
                      name="confirmPassword"
                      value={formData.confirmPassword}
                      onChange={handleChange}
                      autoComplete="new-password"
                      required
                    />
                    <button type="button" className="auth-affix-btn" onClick={() => setShowConfirmPassword((s) => !s)}>
                      {showConfirmPassword ? 'Hide' : 'Show'}
                    </button>
                  </div>
                  {formData.confirmPassword && !confirmValid && (
                    <div className="auth-error" style={{ marginTop: 6, marginBottom: 0 }}>Passwords do not match.</div>
                  )}
                </div>

                <label className="auth-checkbox" style={{ marginBottom: 16 }}>
                  <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
                  <span>
                    I confirm my information is accurate and agree to the Terms of Service and Privacy Policy (Data Privacy Act of 2012).{' '}
                    <Link to="/terms" className="auth-link">Terms of Service</Link> and{' '}
                    <Link to="/privacy" className="auth-link">Privacy Policy</Link>
                  </span>
                </label>

                <button className="auth-btn" type="submit" disabled={submitting}>
                  {submitting ? 'Submitting…' : 'Submit registration'}
                </button>
              </form>

              <p className="auth-switch">
                Already have an account? <Link to="/login" className="auth-link">Sign in</Link>
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
