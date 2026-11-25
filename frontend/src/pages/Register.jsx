import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import {
  AuthAlert,
  AuthField,
  AuthPage,
  AuthPasswordFeedback,
  AuthPillButton,
  AuthSection,
  CheckIcon,
} from '../components/auth/AuthPage';
import OrbitBackground from '../components/OrbitBackground';
import {
  contactNumberCounter,
  contactNumberError,
  isEmailFormat,
  mapRegisterFieldErrors,
  passwordRules,
} from '../lib/authValidation';
import ContactNumberInput from '../components/ContactNumberInput';
import { academicUnitErrors, academicUnitPayload, unitFieldsFor } from '../lib/academicUnits';
import { useAcademicUnits } from '../lib/useAcademicUnits';

// Colleges / units and departments come from the one list on the server
// (GET /api/academic-units), never typed here.

const BRAND = {
  title: 'Request access',
  lead: 'Create an account to monitor externally funded research at Mindanao State University – Main Campus.',
  steps: [
    'Register with your email address',
    'The Program Leader reviews your request and assigns your role',
    'Sign in once you are approved',
  ],
  cta: { to: '/login', label: 'Back to sign in' },
};

const FIELD_IDS = {
  firstName: 'reg-first-name',
  lastName: 'reg-last-name',
  email: 'reg-email',
  contactNumber: 'reg-contact',
  position: 'reg-position',
  college: 'reg-college',
  department: 'reg-department',
  officeName: 'reg-office',
  password: 'reg-password',
  confirmPassword: 'reg-confirm-password',
};

const FIELD_NAMES = Object.keys(FIELD_IDS);

const SERVER_FIELD_MAP = {
  first_name: 'firstName',
  last_name: 'lastName',
  email: 'email',
  contact_number: 'contactNumber',
  office_name: 'officeName',
  position: 'position',
  college: 'college',
  department: 'department',
  password: 'password',
};

// College / department / office name: the shared rule (lib/academicUnits).
const UNIT_ERROR_KEY = { college: 'college', department: 'department', officeName: 'office_name' };

function fieldError(name, data, units) {
  if (UNIT_ERROR_KEY[name]) {
    if (!units) return name === 'college' ? 'The list of colleges and units has not loaded yet.' : '';
    return academicUnitErrors(data, units)[UNIT_ERROR_KEY[name]] ?? '';
  }
  const value = String(data[name] || '').trim();

  switch (name) {
    case 'firstName':
      return value ? '' : 'Enter your first name.';
    case 'lastName':
      return value ? '' : 'Enter your last name.';
    case 'email':
      if (!value) return 'Enter your email address.';
      return isEmailFormat(value) ? '' : 'Enter a valid email address.';
    case 'contactNumber':
      return contactNumberError(value, { required: true });
    case 'position':
      return value ? '' : 'Enter your position.';
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
    <p id={id} className="signin-error" aria-live="polite">
      {message}
    </p>
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
    position: '',
    college: '',
    department: '',
    officeName: '',
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

  const unitList = useAcademicUnits();
  const units = unitList.status === 'ready' ? unitList.data : null;
  const { showDepartment, showOffice, departments, officeLabel, officeMax } = unitFieldsFor(units, formData.college);
  const rules = passwordRules(formData.password, formData.firstName, formData.lastName, formData.email);
  const showPasswordFeedback = formData.password.length > 0;

  function errorFor(name) {
    return serverErrors[name] || (touched[name] ? fieldError(name, formData, units) : '');
  }

  function isValidField(name) {
    return Boolean(touched[name] && !errorFor(name) && String(formData[name] || '').trim());
  }

  function inputClass(name) {
    const error = errorFor(name);
    return [
      'signin-input',
      error ? 'is-invalid' : '',
      isValidField(name) ? 'is-valid' : '',
    ].filter(Boolean).join(' ');
  }

  function describedBy(name, errorId, hintIds = []) {
    const ids = [];
    if (errorFor(name)) ids.push(errorId);
    ids.push(...hintIds.filter(Boolean));
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
      // A new college clears the department (and the office name).
      if (name === 'college') { next.department = ''; next.officeName = ''; }
      return next;
    });

    setServerErrors((prev) => {
      if (!prev[name] && !(name === 'college' && (prev.department || prev.officeName))) return prev;
      const next = { ...prev };
      delete next[name];
      if (name === 'college') { delete next.department; delete next.officeName; }
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

    const firstInvalid = FIELD_NAMES.find((name) => fieldError(name, formData, units)) || (!agree ? 'agree' : null);
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
        contact_number: formData.contactNumber,
        ...academicUnitPayload(formData, units),
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

      if (Object.keys(localErrors).length === 0 && /email.*(already|taken|exists)/i.test(message)) {
        localErrors.email = 'An account with this email already exists.';
      }

      if (Object.keys(localErrors).length > 0) {
        const firstServerField = Object.keys(localErrors)[0];
        setServerErrors(localErrors);
        setTouched((prev) => ({ ...prev, ...Object.fromEntries(Object.keys(localErrors).map((name) => [name, true])) }));
        focusField(firstServerField);
      } else {
        setSubmitError('We could not submit your request. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (success) {
    return (
      <AuthPage variant="register" brand={BRAND} backdrop={<OrbitBackground />}>
        <div className="signin-success">
          <div className="signin-success__icon" aria-hidden="true">
            <CheckIcon />
          </div>
          <h1 className="signin-success__title">Registration submitted</h1>
          <p className="signin-success__text">
            The Program Leader will review your request and assign your role. We will email you at{' '}
            <strong>{submittedEmail}</strong> once your account is approved.
          </p>
          <AuthPillButton
            type="button"
            className="signin-submit--inline"
            onClick={() => navigate('/login', { replace: true })}
          >
            Back to sign in
          </AuthPillButton>
        </div>
      </AuthPage>
    );
  }

  return (
    <AuthPage variant="register" brand={BRAND} backdrop={<OrbitBackground />}>
      <div className="signin-head">
        <h1 className="signin-title">Create account</h1>
        <p className="signin-subtitle">Your role is assigned by the Program Leader after review.</p>
      </div>

      {submitError && <AuthAlert>{submitError}</AuthAlert>}

      <form className="signin-form signin-form--register" onSubmit={handleRegister} noValidate>
        <AuthSection title="Personal details">
          <div className="signin-grid-2">
            <AuthField
              id="reg-first-name"
              label="First name"
              icon="user"
              error={errorFor('firstName')}
            >
              <input
                id="reg-first-name"
                className={inputClass('firstName')}
                type="text"
                name="firstName"
                value={formData.firstName}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="Juan"
                autoComplete="given-name"
                aria-invalid={Boolean(errorFor('firstName'))}
                aria-describedby={describedBy('firstName', 'reg-first-name-error')}
                required
              />
            </AuthField>

            <AuthField
              id="reg-last-name"
              label="Last name"
              icon="user"
              error={errorFor('lastName')}
            >
              <input
                id="reg-last-name"
                className={inputClass('lastName')}
                type="text"
                name="lastName"
                value={formData.lastName}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="Dela Cruz"
                autoComplete="family-name"
                aria-invalid={Boolean(errorFor('lastName'))}
                aria-describedby={describedBy('lastName', 'reg-last-name-error')}
                required
              />
            </AuthField>

            <AuthField
              id="reg-email"
              label="Email address"
              icon="mail"
              error={errorFor('email')}
            >
              <input
                id="reg-email"
                className={inputClass('email')}
                type="email"
                name="email"
                value={formData.email}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="name@example.com"
                autoComplete="email"
                aria-invalid={Boolean(errorFor('email'))}
                aria-describedby={describedBy('email', 'reg-email-error')}
                required
              />
            </AuthField>

            <AuthField
              id="reg-contact"
              label="Contact number"
              icon="phone"
              error={errorFor('contactNumber')}
              hint={contactNumberCounter(formData.contactNumber)}
              hintId="reg-contact-count"
            >
              <ContactNumberInput
                id="reg-contact"
                className={inputClass('contactNumber')}
                name="contactNumber"
                value={formData.contactNumber}
                onValueChange={(value) => handleChange({ target: { name: 'contactNumber', value } })}
                onBlur={handleBlur}
                aria-invalid={Boolean(errorFor('contactNumber'))}
                aria-describedby={describedBy('contactNumber', 'reg-contact-error', ['reg-contact-count'])}
                required
              />
            </AuthField>
          </div>
        </AuthSection>

        <AuthSection title="Employment">
          <div className="signin-grid-2">
            <AuthField
              id="reg-position"
              label="Position"
              icon="briefcase"
              error={errorFor('position')}
            >
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
            </AuthField>
          </div>

          {unitList.status === 'error' && (
            <p className="signin-error" role="alert">
              Could not load the list of colleges and units.{' '}
              <button type="button" className="signin-link" onClick={unitList.retry}>Try again</button>
            </p>
          )}
          <AuthField
            id="reg-college"
            label="College or unit"
            icon="building"
            trailingIcon="chevron"
            error={errorFor('college')}
          >
            <select
              id="reg-college"
              className={inputClass('college')}
              name="college"
              value={formData.college}
              onChange={handleChange}
              onBlur={handleBlur}
              disabled={!units}
              aria-busy={unitList.status === 'loading' || undefined}
              aria-invalid={Boolean(errorFor('college'))}
              aria-describedby={describedBy('college', 'reg-college-error')}
              required
            >
              <option value="">{units ? 'Select a college or unit' : 'Loading colleges and units…'}</option>
              {(units?.units ?? []).map((unit) => (
                <option key={unit.name} value={unit.name}>{unit.name}</option>
              ))}
            </select>
          </AuthField>

          {/* Units without departments hide the field; "Other office / unit" asks for its name. */}
          {showDepartment && (
            <AuthField
              id="reg-department"
              label="Department"
              icon="building"
              trailingIcon="chevron"
              error={errorFor('department')}
            >
              <select
                id="reg-department"
                className={inputClass('department')}
                name="department"
                value={formData.department}
                onChange={handleChange}
                onBlur={handleBlur}
                aria-invalid={Boolean(errorFor('department'))}
                aria-describedby={describedBy('department', 'reg-department-error')}
                required
              >
                <option value="">Select a department</option>
                {departments.map((department) => (
                  <option key={department} value={department}>{department}</option>
                ))}
              </select>
            </AuthField>
          )}

          {showOffice && (
            <AuthField
              id="reg-office"
              label={officeLabel}
              icon="building"
              error={errorFor('officeName')}
            >
              <input
                id="reg-office"
                className={inputClass('officeName')}
                name="officeName"
                value={formData.officeName}
                onChange={handleChange}
                onBlur={handleBlur}
                maxLength={officeMax}
                placeholder="e.g. Office of the Vice Chancellor for Research and Extension"
                aria-invalid={Boolean(errorFor('officeName'))}
                aria-describedby={describedBy('officeName', 'reg-office-error')}
                required
              />
            </AuthField>
          )}
        </AuthSection>

        <AuthSection title="Security">
          <div className="signin-grid-2">
            <AuthField
              id="reg-password"
              label="Password"
              icon="lock"
              error={errorFor('password')}
            >
              <input
                id="reg-password"
                className={`${inputClass('password')} signin-input--password`}
                type={showPasswords ? 'text' : 'password'}
                name="password"
                value={formData.password}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="Enter a password"
                autoComplete="new-password"
                aria-invalid={Boolean(errorFor('password'))}
                aria-describedby={describedBy('password', 'reg-password-error', [
                  'reg-password-hint',
                  showPasswordFeedback ? 'reg-password-rules' : null,
                ])}
                required
              />
              <button
                type="button"
                className="signin-reveal"
                onClick={() => setShowPasswords((value) => !value)}
                aria-pressed={showPasswords}
                aria-controls="reg-password reg-confirm-password"
                aria-label={showPasswords ? 'Hide both passwords' : 'Show both passwords'}
              >
                {showPasswords ? 'Hide' : 'Show'}
              </button>
            </AuthField>

            <AuthField
              id="reg-confirm-password"
              label="Confirm password"
              icon="lock"
              error={errorFor('confirmPassword')}
            >
              <input
                id="reg-confirm-password"
                className={inputClass('confirmPassword')}
                type={showPasswords ? 'text' : 'password'}
                name="confirmPassword"
                value={formData.confirmPassword}
                onChange={handleChange}
                onBlur={handleBlur}
                placeholder="Re-enter your password"
                autoComplete="new-password"
                aria-invalid={Boolean(errorFor('confirmPassword'))}
                aria-describedby={describedBy('confirmPassword', 'reg-confirm-password-error')}
                required
              />
            </AuthField>
          </div>

          <p className="signin-hint" id="reg-password-hint">
            At least 8 characters, with a number and a symbol.
          </p>

          {showPasswordFeedback && (
            <AuthPasswordFeedback rules={rules} id="reg-password-rules" />
          )}
        </AuthSection>

        <div className="signin-field signin-field--terms">
          <label className="signin-check signin-check--terms" htmlFor="reg-agree">
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
              <Link to="/terms" className="signin-text-link">Terms of Service</Link> and{' '}
              <Link to="/privacy" className="signin-text-link">Privacy Policy</Link>{' '}
              (Data Privacy Act of 2012).
            </span>
          </label>
          <FieldError
            id="reg-agree-error"
            message={touched.agree && !agree ? 'Confirm that your information is accurate and accept the policies.' : ''}
          />
        </div>

        <AuthPillButton type="submit" disabled={submitting}>
          {submitting ? 'Submitting…' : 'Create account'}
        </AuthPillButton>

        <p className="signin-switch">
          Already have an account? <Link to="/login" className="signin-text-link">Sign in</Link>
        </p>
      </form>
    </AuthPage>
  );
}
