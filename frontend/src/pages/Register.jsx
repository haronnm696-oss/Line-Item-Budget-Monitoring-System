import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import AuthShell from '../components/auth/AuthShell';
import {
  isValidEmployeeId,
  isValidMsuEmail,
  isValidPhMobile,
  mapRegisterFieldErrors,
  normalizePhMobile,
  passwordRules,
} from '../lib/authValidation';

const COLLEGES = [
  { name: 'College of Computing and Information Sciences', departments: ['Computer Science', 'Information Technology', 'Data Science'] },
  { name: 'College of Business and Finance', departments: ['Finance', 'Accounting'] },
  { name: 'College of Social Sciences and Humanities', departments: ['Research', 'Education'] },
];

const BRAND = {
  title: 'Request access',
  lead: 'Create an account to monitor externally funded research at Mindanao State University – Main Campus.',
  items: [
    'Register with your MSU email',
    'The Program Leader reviews your request and assigns your role',
    'Sign in once you are approved',
  ],
};

const FIELD_IDS = {
  firstName: 'reg-first-name',
  lastName: 'reg-last-name',
  email: 'reg-email',
  contactNumber: 'reg-contact',
  employeeId: 'reg-employee-id',
  position: 'reg-position',
  college: 'reg-college',
  department: 'reg-department',
  password: 'reg-password',
  confirmPassword: 'reg-confirm-password',
};

const FIELD_NAMES = Object.keys(FIELD_IDS);

const SERVER_FIELD_MAP = {
  first_name: 'firstName',
  last_name: 'lastName',
  email: 'email',
  contact_number: 'contactNumber',
  employee_id: 'employeeId',
  position: 'position',
  college: 'college',
  department: 'department',
  password: 'password',
};

function fieldError(name, data) {
  const value = String(data[name] || '').trim();

  switch (name) {
    case 'firstName':
      return value ? '' : 'Enter your first name.';
    case 'lastName':
      return value ? '' : 'Enter your last name.';
    case 'email':
      if (!value) return 'Enter your email address.';
      return isValidMsuEmail(value) ? '' : 'Use your @msumain.edu.ph email address.';
    case 'contactNumber':
      if (!value) return 'Enter your contact number.';
      return isValidPhMobile(value)
        ? ''
        : 'Enter a valid PH mobile number (09XXXXXXXXX or +639XXXXXXXXX).';
    case 'employeeId':
      if (!value) return 'Enter your employee ID.';
      return isValidEmployeeId(value) ? '' : 'Use the format MSU-##### (5 digits).';
    case 'position':
      return value ? '' : 'Enter your position.';
    case 'college':
      return value ? '' : 'Select your college.';
    case 'department':
      return value ? '' : 'Select your department.';
    case 'password': {
      if (!value) return 'Enter a password.';
      const rules = passwordRules(data.password, data.firstName, data.lastName, data.email);
      return rules.every((rule) => rule.met) ? '' : 'Your password must meet all four rules below.';
    }
    case 'confirmPassword':
      if (!value) return 'Confirm your password.';
      return value === data.password ? '' : 'Passwords do not match.';
    default:
      return '';
  }
}

function FieldError({ id, message }) {
  if (!message) return null;
  return (
    <p id={id} className="auth-field__error" aria-live="polite">
      {message}
    </p>
  );
}

function PasswordFeedback({ rules }) {
  return (
    <div className="auth-password-feedback" id="reg-password-rules">
      <div className="auth-strength" aria-hidden="true">
        {rules.map((rule) => (
          <span
            className={`auth-strength__segment${rule.met ? ' is-met' : ''}`}
            key={rule.key}
          />
        ))}
      </div>
      <ul className="auth-rules" aria-label="Password requirements">
        {rules.map((rule) => (
          <li className={`auth-rule${rule.met ? ' is-met' : ''}`} key={rule.key}>
            <span className="auth-rule__mark" aria-hidden="true">{rule.met ? '✓' : '○'}</span>
            <span>{rule.label}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function Register() {
  const navigate = useNavigate();
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
  const [touched, setTouched] = useState({});
  const [serverErrors, setServerErrors] = useState({});
  const [submitError, setSubmitError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState(false);
  const [submittedEmail, setSubmittedEmail] = useState('');
  const [showPasswords, setShowPasswords] = useState(false);

  const selectedCollege = COLLEGES.find((college) => college.name === formData.college);
  const departments = selectedCollege ? selectedCollege.departments : [];
  const rules = passwordRules(formData.password, formData.firstName, formData.lastName, formData.email);
  const showPasswordFeedback = formData.password.length > 0;

  function errorFor(name) {
    return serverErrors[name] || (touched[name] ? fieldError(name, formData) : '');
  }

  function isValidField(name) {
    return Boolean(touched[name] && !errorFor(name) && String(formData[name] || '').trim());
  }

  function inputClass(name) {
    const error = errorFor(name);
    return [
      'auth-input',
      error ? 'is-invalid' : '',
      isValidField(name) ? 'is-valid' : '',
    ].filter(Boolean).join(' ');
  }

  function describedBy(name, errorId, hintId) {
    const ids = [];
    if (errorFor(name)) ids.push(errorId);
    if (hintId) ids.push(hintId);
    return ids.length > 0 ? ids.join(' ') : undefined;
  }

  function focusField(name) {
    const id = name === 'agree' ? 'reg-agree' : FIELD_IDS[name];
    if (id) document.getElementById(id)?.focus();
  }

  function handleChange(e) {
    const { name, value } = e.target;

    setFormData((prev) => {
      const next = { ...prev, [name]: value };
      if (name === 'college') next.department = '';
      return next;
    });

    setServerErrors((prev) => {
      if (!prev[name] && !(name === 'college' && prev.department)) return prev;
      const next = { ...prev };
      delete next[name];
      if (name === 'college') delete next.department;
      return next;
    });
  }

  function handleBlur(e) {
    const { name } = e.target;
    if (!name) return;
    setTouched((prev) => ({ ...prev, [name]: true }));
  }

  async function handleRegister(e) {
    e.preventDefault();

    const nextTouched = Object.fromEntries(
      [...FIELD_NAMES, 'agree'].map((name) => [name, true]),
    );
    setTouched(nextTouched);
    setServerErrors({});
    setSubmitError('');

    const firstInvalid = FIELD_NAMES.find((name) => fieldError(name, formData)) || (!agree ? 'agree' : null);
    if (firstInvalid) {
      focusField(firstInvalid);
      return;
    }

    setSubmitting(true);

    try {
      await signUp({
        first_name: formData.firstName.trim(),
        last_name: formData.lastName.trim(),
        email: formData.email.trim(),
        password: formData.password,
        employee_id: formData.employeeId.trim(),
        contact_number: normalizePhMobile(formData.contactNumber),
        college: formData.college,
        department: formData.department,
        position: formData.position.trim(),
      });

      setSubmittedEmail(formData.email.trim());
      setSuccess(true);
    } catch (err) {
      const message = err?.message || '';
      const mapped = err?.fieldErrors ? mapRegisterFieldErrors(err.fieldErrors) : {};
      const localErrors = {};

      Object.entries(mapped).forEach(([serverField, serverMessage]) => {
        const localField = SERVER_FIELD_MAP[serverField];
        if (localField) localErrors[localField] = serverMessage;
      });

      if (Object.keys(localErrors).length === 0 && /employee id.*(already|taken|exists)/i.test(message)) {
        localErrors.employeeId = 'This employee ID is already registered.';
      }
      if (Object.keys(localErrors).length === 0 && /email.*(already|taken|exists)/i.test(message)) {
        localErrors.email = 'An account with this email already exists.';
      }

      if (Object.keys(localErrors).length > 0) {
        const firstServerField = Object.keys(localErrors)[0];
        setServerErrors(localErrors);
        setTouched((prev) => ({ ...prev, ...Object.fromEntries(Object.keys(localErrors).map((name) => [name, true])) }));
        focusField(firstServerField);
      } else if (err?.status === 429) {
        setSubmitError('Too many attempts. Please wait a minute and try again.');
      } else if (message && !/server error/i.test(message)) {
        setSubmitError(message);
      } else {
        setSubmitError('We could not submit your request. Check your connection and try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (success) {
    return (
      <AuthShell
        title={BRAND.title}
        lead={BRAND.lead}
        items={BRAND.items}
        numbered
        maxWidth={580}
      >
        <div className="auth-success">
          <div className="auth-success__icon" aria-hidden="true">✓</div>
          <h1 className="auth-form__title">Registration submitted</h1>
          <p className="auth-success__text">
            The Program Leader will review your request and assign your role. We will email you at{' '}
            <strong>{submittedEmail}</strong> once your account is approved.
          </p>
          <button
            type="button"
            className="auth-btn"
            onClick={() => navigate('/login', { replace: true })}
          >
            Back to sign in
          </button>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title={BRAND.title}
      lead={BRAND.lead}
      items={BRAND.items}
      numbered
      maxWidth={580}
    >
      <div className="auth-form__heading">
        <h1 className="auth-form__title">Create account</h1>
        <p className="auth-form__subtitle">Your role is assigned by the Program Leader after review.</p>
      </div>

      {submitError && <div className="auth-error" role="alert">{submitError}</div>}

      <form className="auth-form__body" onSubmit={handleRegister} noValidate>
        <section className="auth-section">
          <h3 className="auth-section-title">Personal details</h3>
          <div className="auth-grid-2">
            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-first-name">First name</label>
              <input
                id="reg-first-name"
                className={inputClass('firstName')}
                type="text"
                name="firstName"
                value={formData.firstName}
                onChange={handleChange}
                onBlur={handleBlur}
                autoComplete="given-name"
                aria-invalid={Boolean(errorFor('firstName'))}
                aria-describedby={describedBy('firstName', 'reg-first-name-error')}
                required
              />
              <FieldError id="reg-first-name-error" message={errorFor('firstName')} />
            </div>

            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-last-name">Last name</label>
              <input
                id="reg-last-name"
                className={inputClass('lastName')}
                type="text"
                name="lastName"
                value={formData.lastName}
                onChange={handleChange}
                onBlur={handleBlur}
                autoComplete="family-name"
                aria-invalid={Boolean(errorFor('lastName'))}
                aria-describedby={describedBy('lastName', 'reg-last-name-error')}
                required
              />
              <FieldError id="reg-last-name-error" message={errorFor('lastName')} />
            </div>

            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-email">Email address</label>
              <input
                id="reg-email"
                className={inputClass('email')}
                type="email"
                name="email"
                value={formData.email}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="name@msumain.edu.ph"
                autoComplete="email"
                aria-invalid={Boolean(errorFor('email'))}
                aria-describedby={describedBy('email', 'reg-email-error')}
                required
              />
              <FieldError id="reg-email-error" message={errorFor('email')} />
            </div>

            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-contact">Contact number</label>
              <input
                id="reg-contact"
                className={inputClass('contactNumber')}
                type="tel"
                name="contactNumber"
                value={formData.contactNumber}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="+63 917 123 4567"
                autoComplete="tel"
                inputMode="tel"
                aria-invalid={Boolean(errorFor('contactNumber'))}
                aria-describedby={describedBy('contactNumber', 'reg-contact-error')}
                required
              />
              <FieldError id="reg-contact-error" message={errorFor('contactNumber')} />
            </div>
          </div>
        </section>

        <section className="auth-section">
          <h3 className="auth-section-title">Employment</h3>
          <div className="auth-grid-2">
            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-employee-id">Employee ID</label>
              <input
                id="reg-employee-id"
                className={inputClass('employeeId')}
                type="text"
                name="employeeId"
                value={formData.employeeId}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="MSU-04182"
                autoComplete="off"
                aria-invalid={Boolean(errorFor('employeeId'))}
                aria-describedby={describedBy('employeeId', 'reg-employee-id-error')}
                required
              />
              <FieldError id="reg-employee-id-error" message={errorFor('employeeId')} />
            </div>

            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-position">Position</label>
              <input
                id="reg-position"
                className={inputClass('position')}
                type="text"
                name="position"
                value={formData.position}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="e.g. Professor VI"
                aria-invalid={Boolean(errorFor('position'))}
                aria-describedby={describedBy('position', 'reg-position-error')}
                required
              />
              <FieldError id="reg-position-error" message={errorFor('position')} />
            </div>
          </div>

          <div className="auth-field">
            <label className="auth-label" htmlFor="reg-college">College</label>
            <select
              id="reg-college"
              className={inputClass('college')}
              name="college"
              value={formData.college}
              onChange={handleChange}
              onBlur={handleBlur}
              aria-invalid={Boolean(errorFor('college'))}
              aria-describedby={describedBy('college', 'reg-college-error')}
              required
            >
              <option value="">Select a college</option>
              {COLLEGES.map((college) => (
                <option key={college.name} value={college.name}>{college.name}</option>
              ))}
            </select>
            <FieldError id="reg-college-error" message={errorFor('college')} />
          </div>

          <div className="auth-field">
            <label className="auth-label" htmlFor="reg-department">Department</label>
            <select
              id="reg-department"
              className={inputClass('department')}
              name="department"
              value={formData.department}
              onChange={handleChange}
              onBlur={handleBlur}
              disabled={!formData.college}
              aria-invalid={Boolean(errorFor('department'))}
              aria-describedby={describedBy('department', 'reg-department-error')}
              required
            >
              <option value="">{formData.college ? 'Select a department' : 'Select a college first'}</option>
              {departments.map((department) => (
                <option key={department} value={department}>{department}</option>
              ))}
            </select>
            <FieldError id="reg-department-error" message={errorFor('department')} />
          </div>
        </section>

        <section className="auth-section">
          <h3 className="auth-section-title">Security</h3>
          <div className="auth-grid-2">
            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-password">Password</label>
              <div className="auth-input-affix">
                <input
                  id="reg-password"
                  className={inputClass('password')}
                  type={showPasswords ? 'text' : 'password'}
                  name="password"
                  value={formData.password}
                  onChange={handleChange}
                  onBlur={handleBlur}
                  autoComplete="new-password"
                  aria-invalid={Boolean(errorFor('password'))}
                  aria-describedby={describedBy('password', 'reg-password-error', showPasswordFeedback ? 'reg-password-rules' : undefined)}
                  required
                />
                <button
                  type="button"
                  className="auth-affix-btn"
                  onClick={() => setShowPasswords((value) => !value)}
                  aria-pressed={showPasswords}
                  aria-controls="reg-password reg-confirm-password"
                  aria-label={showPasswords ? 'Hide both passwords' : 'Show both passwords'}
                >
                  {showPasswords ? 'Hide' : 'Show'}
                </button>
              </div>
              <FieldError id="reg-password-error" message={errorFor('password')} />
              {showPasswordFeedback && <PasswordFeedback rules={rules} />}
            </div>

            <div className="auth-field">
              <label className="auth-label" htmlFor="reg-confirm-password">Confirm password</label>
              <input
                id="reg-confirm-password"
                className={inputClass('confirmPassword')}
                type={showPasswords ? 'text' : 'password'}
                name="confirmPassword"
                value={formData.confirmPassword}
                onChange={handleChange}
                onBlur={handleBlur}
                autoComplete="new-password"
                aria-invalid={Boolean(errorFor('confirmPassword'))}
                aria-describedby={describedBy('confirmPassword', 'reg-confirm-password-error')}
                required
              />
              <FieldError id="reg-confirm-password-error" message={errorFor('confirmPassword')} />
            </div>
          </div>
        </section>

        <div className="auth-field">
          <label className="auth-checkbox" htmlFor="reg-agree">
            <input
              id="reg-agree"
              type="checkbox"
              checked={agree}
              onChange={(e) => setAgree(e.target.checked)}
              aria-invalid={Boolean(touched.agree && !agree)}
              aria-describedby={touched.agree && !agree ? 'reg-agree-error' : undefined}
              required
            />
            <span>
              I confirm my information is accurate and agree to the{' '}
              <Link to="/terms" className="auth-link">Terms of Service</Link> and{' '}
              <Link to="/privacy" className="auth-link">Privacy Policy</Link>{' '}
              (Data Privacy Act of 2012).
            </span>
          </label>
          <FieldError
            id="reg-agree-error"
            message={touched.agree && !agree ? 'Confirm that your information is accurate and accept the policies.' : ''}
          />
        </div>

        <button className="auth-btn" type="submit" disabled={submitting}>
          {submitting ? 'Submitting…' : 'Create account'}
        </button>
      </form>

      <p className="auth-switch">
        Already have an account? <Link to="/login" className="auth-link">Sign in</Link>
      </p>
    </AuthShell>
  );
}
