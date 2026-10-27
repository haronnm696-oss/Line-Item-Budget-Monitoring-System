import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { ROUTE_BY_ROLE } from './Dashboard';

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

const POSITION_BY_ROLE = {
  'program-leader': 'Program Leader',
  'project-leader': 'Project Leader',
  'research-assistant': 'Research Assistant',
  'researcher': 'Researcher',
  'finance-admin': 'Finance Officer',
};

export default function Register() {
  const navigate = useNavigate();
  const { signUp } = useAuth();
  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    contactNumber: '',
    employeeId: '',
    college: '',
    department: '',
    password: '',
    confirmPassword: '',
    role: 'project-leader',
  });
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [agreeAccuracy, setAgreeAccuracy] = useState(false);
  const [agreeTerms, setAgreeTerms] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => {
      const next = { ...prev, [name]: value };
      if (name === 'college') {
        next.department = '';
      }
      return next;
    });
  };

  const selectedCollege = COLLEGES.find((c) => c.name === formData.college);
  const departments = selectedCollege ? selectedCollege.departments : [];

  const strength = getPasswordStrength(formData.password);
  const strengthLabel = STRENGTH_LABELS[strength];

  const handleRegister = async (e) => {
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
    if (!agreeAccuracy || !agreeTerms) {
      setError('Please accept both policy agreements to continue.');
      return;
    }
    const employeeId = formData.employeeId.trim();
    const email = formData.email.trim();
    if (employeeId && employeeId.toLowerCase() === email.toLowerCase()) {
      setError('Employee ID cannot be the same as your email address.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      const user = await signUp({
        first_name: formData.firstName,
        last_name: formData.lastName,
        email,
        password: formData.password,
        employee_id: employeeId || undefined,
        contact_number: formData.contactNumber || undefined,
        college: formData.college || undefined,
        department: formData.department || undefined,
        role: formData.role,
        position: POSITION_BY_ROLE[formData.role] || 'Research Assistant',
      });
      const path = user?.role ? ROUTE_BY_ROLE[user.role] || '/dashboard' : '/dashboard';
      navigate(path, { replace: true });
    } catch (err) {
      setError(err?.message || 'Registration failed. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const inputStyle = {
    width: '100%',
    backgroundColor: 'rgba(0, 0, 0, 0.35)',
    border: '1px solid rgba(255, 255, 255, 0.25)',
    color: '#ffffff',
    borderRadius: '10px',
    padding: '11px 14px',
    fontSize: '13px',
    outline: 'none',
    boxSizing: 'border-box',
  };

  const fieldLabelStyle = {
    display: 'block',
    fontSize: '12px',
    fontWeight: '600',
    color: 'rgba(254, 205, 211, 0.9)',
    marginBottom: '4px',
  };

  return (
    <div 
      style={{ 
        minHeight: '100vh',
        width: '100%',
        background: 'radial-gradient(circle at top left, #5a061c, #26020b, #110005)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '32px 16px',
        fontFamily: 'system-ui, -apple-system, sans-serif',
        boxSizing: 'border-box'
      }}
    >
      {/* Main Glassmorphic Container Card */}
      <div 
        style={{ 
          width: '100%',
          maxWidth: '1000px',
          minHeight: '600px',
          borderRadius: '24px',
          border: '1px solid rgba(255, 255, 255, 0.15)',
          background: 'rgba(20, 2, 7, 0.45)',
          backdropFilter: 'blur(20px)',
          WebkitBackdropFilter: 'blur(20px)',
          boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.7)',
          display: 'flex',
          flexWrap: 'wrap',
          overflow: 'hidden',
          position: 'relative'
        }}
      >
        {/* Left Hero Section (Branding & Guidance) */}
        <div 
          style={{ 
            flex: '1 1 450px',
            padding: '48px',
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'space-between',
            boxSizing: 'border-box'
          }}
        >
          <div>
            {/* Logo Badge */}
            <div style={{ display: 'inline-flex', alignItems: 'center', gap: '8px', marginBottom: '32px' }}>
              <div 
                style={{ 
                  width: '36px', height: '36px', backgroundColor: '#f59e0b', borderRadius: '10px',
                  display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: '900',
                  color: '#26020b', fontSize: '14px', boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.3)'
                }}
              >
                M
              </div>
              <span style={{ color: '#ffffff', fontWeight: '800', fontSize: '13px', letterSpacing: '1px' }}>MSU-MAIN</span>
            </div>

            {/* Title */}
            <h1 style={{ fontSize: '42px', fontWeight: '900', color: '#ffffff', margin: '0 0 16px 0', tracking: '-1px' }}>
              Let's get started<span style={{ color: '#fbbf24' }}>!</span>
            </h1>

            <div style={{ width: '60px', height: '4px', background: 'linear-gradient(to right, #fbbf24, #f43f5e)', borderRadius: '2px', marginBottom: '24px' }} />

            <p style={{ color: 'rgba(254, 205, 211, 0.8)', fontSize: '15px', lineHeight: '1.6', maxWidth: '380px', margin: '0 0 28px 0' }}>
              Create your account to monitor externally funded research projects at Mindanao State University – Main Campus.
            </p>

            {/* Benefit Checkmarks */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', color: '#ffffff', fontSize: '13px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <span style={{ width: '22px', height: '22px', borderRadius: '50%', backgroundColor: 'rgba(255,255,255,0.1)', border: '1px solid rgba(251, 191, 36, 0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fcd34d', fontSize: '11px', fontWeight: 'bold' }}>✓</span>
                <span>Create your institutional research account</span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <span style={{ width: '22px', height: '22px', borderRadius: '50%', backgroundColor: 'rgba(255,255,255,0.1)', border: '1px solid rgba(251, 191, 36, 0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fcd34d', fontSize: '11px', fontWeight: 'bold' }}>✓</span>
                <span>Assign a role for project tracking access</span>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <span style={{ width: '22px', height: '22px', borderRadius: '50%', backgroundColor: 'rgba(255,255,255,0.1)', border: '1px solid rgba(251, 191, 36, 0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fcd34d', fontSize: '11px', fontWeight: 'bold' }}>✓</span>
                <span>Start monitoring funded research programs</span>
              </div>
            </div>
          </div>

          <div style={{ fontSize: '11px', color: 'rgba(254, 205, 211, 0.4)', fontWeight: '700', letterSpacing: '1px', textTransform: 'uppercase', marginTop: '32px' }}>
            MSU-MAIN CAMPUS · EXTERNALLY FUNDED RESEARCH
          </div>
        </div>

        {/* Right Section - Floating Glassmorphic Registration Box */}
        <div 
          style={{ 
            flex: '1 1 450px',
            padding: '32px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxSizing: 'border-box'
          }}
        >
          <div 
            style={{ 
              width: '100%',
              backgroundColor: 'rgba(255, 255, 255, 0.08)',
              border: '1px solid rgba(255, 255, 255, 0.2)',
              borderRadius: '20px',
              padding: '32px',
              backdropFilter: 'blur(16px)',
              WebkitBackdropFilter: 'blur(16px)',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.5)',
              boxSizing: 'border-box'
            }}
          >
            <div style={{ marginBottom: '20px' }}>
              <h2 style={{ fontSize: '24px', fontWeight: '800', color: '#ffffff', margin: 0 }}>
                Create <span style={{ borderBottom: '3px solid #fbbf24', paddingBottom: '2px' }}>account</span>
              </h2>
              <p style={{ fontSize: '12px', color: 'rgba(254, 205, 211, 0.7)', marginTop: '6px' }}>
                Fill in the details to register for the program.
              </p>
            </div>

            {error && (
              <div style={{ padding: '10px', backgroundColor: 'rgba(239, 68, 68, 0.2)', border: '1px solid rgba(239, 68, 68, 0.4)', borderRadius: '10px', color: '#fecdd3', fontSize: '12px', marginBottom: '16px' }}>
                {error}
              </div>
            )}

            <form onSubmit={handleRegister} style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
              {/* First Name & Last Name */}
              <div style={{ display: 'flex', gap: '12px' }}>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>First Name</label>
                  <input type="text" name="firstName" value={formData.firstName} onChange={handleChange} placeholder="e.g. Juan" style={inputStyle} required />
                </div>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Last Name</label>
                  <input type="text" name="lastName" value={formData.lastName} onChange={handleChange} placeholder="e.g. Dela Cruz" style={inputStyle} required />
                </div>
              </div>

              {/* Email Address & Contact Number */}
              <div style={{ display: 'flex', gap: '12px' }}>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Email address</label>
                  <input type="email" name="email" value={formData.email} onChange={handleChange} placeholder="name@msumain.edu.ph" style={inputStyle} autoComplete="email" required />
                </div>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Contact Number</label>
                  <input type="text" name="contactNumber" value={formData.contactNumber} onChange={handleChange} placeholder="e.g. 0917-123-4567" style={inputStyle} autoComplete="tel" />
                </div>
              </div>

              {/* Employee ID & Role */}
              <div style={{ display: 'flex', gap: '12px' }}>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Employee ID</label>
                  <input type="text" name="employeeId" value={formData.employeeId} onChange={handleChange} placeholder="e.g. MSU-04182" style={inputStyle} autoComplete="off" />
                </div>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Role</label>
                  <select name="role" value={formData.role} onChange={handleChange} style={{ ...inputStyle, appearance: 'auto' }}>
                    <option value="program-leader">Program Leader</option>
                    <option value="project-leader">Project Leader</option>
                    <option value="research-assistant">Research Assistant</option>
                    <option value="researcher">Researcher</option>
                    <option value="finance-admin">Finance/Admin Staff</option>
                  </select>
                </div>
              </div>

              {/* College & Department */}
              <div style={{ display: 'flex', gap: '12px' }}>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>College</label>
                  <select name="college" value={formData.college} onChange={handleChange} style={{ ...inputStyle, appearance: 'auto' }}>
                    <option value="">Select a college</option>
                    {COLLEGES.map((c) => (
                      <option key={c.name} value={c.name}>{c.name}</option>
                    ))}
                  </select>
                </div>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Department</label>
                  <select name="department" value={formData.department} onChange={handleChange} disabled={!formData.college} style={{ ...inputStyle, appearance: 'auto', opacity: formData.college ? 1 : 0.6 }}>
                    <option value="">{formData.college ? 'Select a department' : 'Select a college first'}</option>
                    {departments.map((d) => (
                      <option key={d} value={d}>{d}</option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Password & Confirm Password */}
              <div style={{ display: 'flex', gap: '12px' }}>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Password</label>
                  <div style={{ position: 'relative' }}>
                    <input
                      type={showPassword ? 'text' : 'password'}
                      name="password"
                      value={formData.password}
                      onChange={handleChange}
                      placeholder="Enter your password"
                      style={{ ...inputStyle, paddingRight: '40px' }}
                      required
                    />
                    <button type="button" onClick={() => setShowPassword(!showPassword)} style={{ position: 'absolute', right: '12px', top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'rgba(255,255,255,0.6)', cursor: 'pointer', fontSize: '14px' }}>
                      {showPassword ? '🙈' : '👁️'}
                    </button>
                  </div>
                  {formData.password && (
                    <div style={{ marginTop: '6px' }}>
                      <div style={{ height: '4px', backgroundColor: 'rgba(255, 255, 255, 0.15)', borderRadius: '2px', overflow: 'hidden' }}>
                        <div style={{ width: `${(strength / 5) * 100}%`, height: '4px', backgroundColor: strength >= 4 ? '#22c55e' : strength >= 3 ? '#f59e0b' : '#ef4444', borderRadius: '2px' }} />
                      </div>
                      <div style={{ fontSize: '11px', color: 'rgba(254, 205, 211, 0.7)', marginTop: '4px' }}>Password strength: {strengthLabel}</div>
                    </div>
                  )}
                </div>
                <div style={{ flex: 1 }}>
                  <label style={fieldLabelStyle}>Confirm Password</label>
                  <div style={{ position: 'relative' }}>
                    <input
                      type={showConfirmPassword ? 'text' : 'password'}
                      name="confirmPassword"
                      value={formData.confirmPassword}
                      onChange={handleChange}
                      placeholder="Re-enter your password"
                      style={{ ...inputStyle, paddingRight: '40px' }}
                      required
                    />
                    <button type="button" onClick={() => setShowConfirmPassword(!showConfirmPassword)} style={{ position: 'absolute', right: '12px', top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', color: 'rgba(255,255,255,0.6)', cursor: 'pointer', fontSize: '14px' }}>
                      {showConfirmPassword ? '🙈' : '👁️'}
                    </button>
                  </div>
                </div>
              </div>

              {/* Policy Checkboxes */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginTop: '4px' }}>
                <label style={{ display: 'flex', alignItems: 'flex-start', gap: '8px', fontSize: '11px', color: 'rgba(254, 205, 211, 0.7)', cursor: 'pointer' }}>
                  <input type="checkbox" checked={agreeAccuracy} onChange={(e) => setAgreeAccuracy(e.target.checked)} style={{ marginTop: '2px' }} />
                  <span>I confirm that the information provided is accurate and I agree to the research data policies of Mindanao State University – Main Campus.</span>
                </label>
                <label style={{ display: 'flex', alignItems: 'flex-start', gap: '8px', fontSize: '11px', color: 'rgba(254, 205, 211, 0.7)', cursor: 'pointer' }}>
                  <input type="checkbox" checked={agreeTerms} onChange={(e) => setAgreeTerms(e.target.checked)} style={{ marginTop: '2px' }} />
                  <span>By creating an account, you agree to our Terms of Service and Privacy Policy.</span>
                </label>
              </div>

              {/* Submit Button */}
              <button
                type="submit"
                disabled={submitting}
                style={{
                  width: '100%',
                  marginTop: '10px',
                  background: 'linear-gradient(to right, #f59e0b, #e11d48)',
                  border: 'none',
                  color: '#ffffff',
                  fontWeight: '700',
                  fontSize: '13px',
                  padding: '12px',
                  borderRadius: '10px',
                  cursor: submitting ? 'not-allowed' : 'pointer',
                  boxShadow: '0 10px 15px -3px rgba(225, 29, 72, 0.3)'
                }}
              >
                {submitting ? 'Creating account...' : 'Create Account'}
              </button>
            </form>

            <p style={{ textAlign: 'center', fontSize: '12px', color: 'rgba(254, 205, 211, 0.6)', marginTop: '20px' }}>
              Already have an account?{' '}
              <Link to="/login" style={{ color: '#fbbf24', fontWeight: '600', textDecoration: 'underline' }}>
                Sign in
              </Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
