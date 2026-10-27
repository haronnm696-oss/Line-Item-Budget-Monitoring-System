import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ROUTE_BY_ROLE } from './Dashboard';
import { ROLE_MAP, normalizeRole } from '../data/mockData';
import { useAuth } from '../context/AuthContext';

// All assignable roles, derived from the canonical ROLE_MAP so the login
// selector and post-login routing always stay in sync.
const ALL_ROLES = Object.entries(ROLE_MAP).map(([code, info]) => ({
  code,
  label: info.label,
  path: ROUTE_BY_ROLE[code] || '/dashboard',
}));

const DEFAULT_ROLE = 'PRG';

// Map a role (code or label-ish value) to its dashboard URL.
function getDashboardPath(roleCode) {
  const code = normalizeRole({ role: roleCode }) || DEFAULT_ROLE;
  return ROUTE_BY_ROLE[code] || '/dashboard';
}

// Mock Google OAuth accounts offered in the "Continue with Google" picker,
// each pre-assigned to the dashboard they should land on.
const GOOGLE_ACCOUNTS = [
  { email: 'r.bayabao@msumain.edu.ph', name: 'Dr. Rahana Bayabao', initials: 'RB', role: 'PRG' },
  { email: 'a.macarambon@msumain.edu.ph', name: 'Dr. Aminah Macarambon', initials: 'AM', role: 'PL' },
];

export default function Login() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('macadaub.jnnh@msumain.edu.ph');
  const [password, setPassword] = useState('password123');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [isGoogleModalOpen, setIsGoogleModalOpen] = useState(false);
  const [selectedRole, setSelectedRole] = useState(() => {
    try {
      const raw = JSON.parse(localStorage.getItem('user') || '{}');
      return normalizeRole(raw) || DEFAULT_ROLE;
    } catch {
      return DEFAULT_ROLE;
    }
  });

  const { signIn } = useAuth();
  const [submitting, setSubmitting] = useState(false);

  const handleLogin = async (e) => {
    e?.preventDefault();
    if (!email || !password) {
      setError('Please fill in all fields.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      // Sign in through the Laravel API (axios -> Sanctum session -> MySQL)
      const user = await signIn(email, password);
      // Route to the dashboard mapped to the user's role
      const path = getDashboardPath(user?.role);
      navigate(path, { replace: true });
    } catch (err) {
      setError(err?.message || 'Sign in failed. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleGoogleSelect = (userEmail) => {
    const account = GOOGLE_ACCOUNTS.find((a) => a.email === userEmail);
    const roleCode = account?.role || selectedRole;
    localStorage.setItem('user', JSON.stringify({ email: userEmail, role: roleCode }));
    setIsGoogleModalOpen(false);
    navigate(getDashboardPath(roleCode), { replace: true });
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
      {/* Outer Main Container Card */}
      <div 
        style={{
          width: '100%',
          maxWidth: '1000px',
          minHeight: '580px',
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
        {/* Left Hero Section (Branding & Info) */}
        <div 
          style={{
            flex: '1 1 500px',
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
                  width: '36px',
                  height: '36px',
                  backgroundColor: '#f59e0b',
                  borderRadius: '10px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontWeight: '900',
                  color: '#26020b',
                  fontSize: '14px',
                  boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.3)'
                }}
              >
                M
              </div>
              <span style={{ color: '#ffffff', fontWeight: '800', fontSize: '13px', letterSpacing: '1px' }}>
                MSU-MAIN
              </span>
            </div>

            {/* Title */}
            <h1 style={{ fontSize: '48px', fontWeight: '900', color: '#ffffff', margin: '0 0 16px 0', tracking: '-1px' }}>
              Welcome<span style={{ color: '#fbbf24' }}>!</span>
            </h1>

            <div style={{ width: '60px', height: '4px', background: 'linear-gradient(to right, #fbbf24, #f43f5e)', borderRadius: '2px', marginBottom: '24px' }} />

            <p style={{ color: 'rgba(254, 205, 211, 0.8)', fontSize: '15px', lineHeight: '1.6', maxWidth: '400px', margin: '0 0 24px 0' }}>
              LIB Monitoring System for Externally Funded Research Projects at Mindanao State University – Main Campus.
            </p>

            <div 
              style={{
                display: 'inline-block',
                padding: '6px 14px',
                backgroundColor: 'rgba(245, 158, 11, 0.15)',
                border: '1px solid rgba(245, 158, 11, 0.3)',
                borderRadius: '9999px',
                color: '#fcd34d',
                fontSize: '11px',
                fontWeight: '700',
                letterSpacing: '0.5px'
              }}
            >
              EXTERNALLY FUNDED RESEARCH
            </div>
          </div>

          <div style={{ fontSize: '11px', color: 'rgba(254, 205, 211, 0.4)', fontWeight: '700', letterSpacing: '1px', textTransform: 'uppercase', marginTop: '32px' }}>
            MSU-MAIN CAMPUS · EXTERNALLY FUNDED RESEARCH
          </div>
        </div>

        {/* Right Section - Floating Glassmorphic Sign-In Box */}
        <div 
          style={{
            flex: '1 1 400px',
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
            <div style={{ marginBottom: '24px' }}>
              <h2 style={{ fontSize: '24px', fontWeight: '800', color: '#ffffff', margin: 0 }}>
                Sign <span style={{ borderBottom: '3px solid #fbbf24', paddingBottom: '2px' }}>in</span>
              </h2>
              <p style={{ fontSize: '12px', color: 'rgba(254, 205, 211, 0.7)', marginTop: '6px' }}>
                Sign in to continue to your research dashboard.
              </p>
            </div>

            {error && (
              <div style={{ padding: '10px', backgroundColor: 'rgba(239, 68, 68, 0.2)', border: '1px solid rgba(239, 68, 68, 0.4)', borderRadius: '10px', color: '#fecdd3', fontSize: '12px', marginBottom: '16px' }}>
                {error}
              </div>
            )}

            <form onSubmit={handleLogin} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
              <div>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', color: 'rgba(254, 205, 211, 0.9)', marginBottom: '6px' }}>
                  Email address
                </label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="name@msumain.edu.ph"
                  style={{
                    width: '100%',
                    backgroundColor: 'rgba(0, 0, 0, 0.35)',
                    border: '1px solid rgba(255, 255, 255, 0.25)',
                    color: '#ffffff',
                    borderRadius: '10px',
                    padding: '12px 14px',
                    fontSize: '13px',
                    outline: 'none',
                    boxSizing: 'border-box'
                  }}
                  required
                />
              </div>

              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                  <label style={{ fontSize: '12px', fontWeight: '600', color: 'rgba(254, 205, 211, 0.9)' }}>
                    Password
                  </label>
                  <a href="#forgot" style={{ fontSize: '11px', color: '#fbbf24', textDecoration: 'none' }}>
                    Forgot password?
                  </a>
                </div>
                <div style={{ position: 'relative' }}>
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    style={{
                      width: '100%',
                      backgroundColor: 'rgba(0, 0, 0, 0.35)',
                      border: '1px solid rgba(255, 255, 255, 0.25)',
                      color: '#ffffff',
                      borderRadius: '10px',
                      padding: '12px 40px 12px 14px',
                      fontSize: '13px',
                      outline: 'none',
                      boxSizing: 'border-box'
                    }}
                    required
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    style={{
                      position: 'absolute',
                      right: '12px',
                      top: '50%',
                      transform: 'translateY(-50%)',
                      background: 'none',
                      border: 'none',
                      color: 'rgba(255,255,255,0.6)',
                      cursor: 'pointer',
                      fontSize: '14px'
                    }}
                  >
                    {showPassword ? '🙈' : '👁️'}
                  </button>
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', color: 'rgba(254, 205, 211, 0.9)', marginBottom: '6px' }}>
                  Role
                </label>
                <select
                  value={selectedRole}
                  onChange={(e) => setSelectedRole(e.target.value)}
                  style={{
                    width: '100%',
                    backgroundColor: 'rgba(0, 0, 0, 0.35)',
                    border: '1px solid rgba(255, 255, 255, 0.25)',
                    color: '#ffffff',
                    borderRadius: '10px',
                    padding: '12px 14px',
                    fontSize: '13px',
                    outline: 'none',
                    boxSizing: 'border-box'
                  }}
                >
                  {ALL_ROLES.map(({ code, label }) => (
                    <option key={code} value={code}>{label}</option>
                  ))}
                </select>
              </div>

              <button
                type="submit"
                disabled={submitting}
                style={{
                  width: '100%',
                  marginTop: '8px',
                  background: 'linear-gradient(to right, #f59e0b, #e11d48)',
                  border: 'none',
                  color: '#ffffff',
                  fontWeight: '700',
                  fontSize: '13px',
                  padding: '12px',
                  borderRadius: '10px',
                  cursor: 'pointer',
                  boxShadow: '0 10px 15px -3px rgba(225, 29, 72, 0.3)'
                }}
              >
                Sign In
              </button>
            </form>

            <div style={{ position: 'relative', margin: '20px 0', textAlign: 'center' }}>
              <div style={{ borderTop: '1px solid rgba(255, 255, 255, 0.15)', width: '100%' }} />
              <span style={{ position: 'absolute', top: '-8px', left: '50%', transform: 'translateX(-50%)', backgroundColor: '#2d040f', padding: '0 8px', color: 'rgba(254, 205, 211, 0.5)', fontSize: '10px', fontWeight: '700' }}>
                OR
              </span>
            </div>

            <button
              type="button"
              onClick={() => setIsGoogleModalOpen(true)}
              style={{
                width: '100%',
                backgroundColor: 'rgba(255, 255, 255, 0.08)',
                border: '1px solid rgba(255, 255, 255, 0.25)',
                color: '#ffffff',
                fontSize: '12px',
                fontWeight: '600',
                padding: '10px',
                borderRadius: '10px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '8px'
              }}
            >
              <svg style={{ width: '16px', height: '16px' }} viewBox="0 0 24 24">
                <path fill="#EA4335" d="M12 5c1.6 0 3 .6 4.1 1.6l3.1-3.1C17.3 1.7 14.8 1 12 1 7.5 1 3.7 3.6 1.9 7.3l3.7 2.9C6.5 7.1 9 5 12 5z" />
                <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5c-.3 1.5-1.1 2.8-2.4 3.7l3.7 2.9c2.2-2 3.7-5 3.7-8.8z" />
                <path fill="#FBBC05" d="M5.6 14.8c-.2-.7-.4-1.5-.4-2.3s.2-1.6.4-2.3L1.9 7.3C.7 9.7 0 12.3 0 15s.7 5.3 1.9 7.7l3.7-2.9z" />
                <path fill="#34A853" d="M12 23c3.2 0 6-1.1 8-3l-3.7-2.9c-1.1.7-2.5 1.2-4.3 1.2-3 0-5.5-2.1-6.4-5.2L1.9 16C3.7 19.7 7.5 23 12 23z" />
              </svg>
              Continue with Google
            </button>

            <p style={{ textAlign: 'center', fontSize: '12px', color: 'rgba(254, 205, 211, 0.6)', marginTop: '16px' }}>
              Don't have an account?{' '}
              <Link to="/register" style={{ color: '#fbbf24', fontWeight: '600', textDecoration: 'underline' }}>
                Register here
              </Link>
            </p>
          </div>
        </div>
      </div>

      {/* Google Modal */}
      {isGoogleModalOpen && (
        <div style={{ position: 'fixed', inset: 0, zIndex: 50, backgroundColor: 'rgba(0,0,0,0.75)', backdropFilter: 'blur(5px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '16px' }}>
          <div style={{ backgroundColor: '#1e030b', border: '1px solid rgba(255,255,255,0.2)', width: '100%', maxWidth: '360px', borderRadius: '16px', padding: '24px', color: '#fff' }}>
            <h3 style={{ fontSize: '16px', fontWeight: '700', margin: '0 0 4px 0' }}>Choose an Account</h3>
            <p style={{ fontSize: '12px', color: 'rgba(254,205,211,0.6)', margin: '0 0 16px 0' }}>to continue to MSU LIB Monitoring</p>
            
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '16px' }}>
              <button
                onClick={() => handleGoogleSelect('r.bayabao@msumain.edu.ph')}
                style={{ textAlign: 'left', padding: '12px', borderRadius: '10px', backgroundColor: 'rgba(255,255,255,0.08)', border: '1px solid rgba(255,255,255,0.1)', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '12px', color: '#fff' }}
              >
                <div style={{ width: '32px', height: '32px', borderRadius: '50%', backgroundColor: '#f59e0b', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: '700', fontSize: '12px', color: '#000' }}>RB</div>
                <div>
                  <div style={{ fontSize: '12px', fontWeight: '600' }}>Dr. Rahana Bayabao</div>
                  <div style={{ fontSize: '11px', color: 'rgba(254,205,211,0.6)' }}>r.bayabao@msumain.edu.ph</div>
                </div>
              </button>

              <button
                onClick={() => handleGoogleSelect('a.macarambon@msumain.edu.ph')}
                style={{ textAlign: 'left', padding: '12px', borderRadius: '10px', backgroundColor: 'rgba(255,255,255,0.08)', border: '1px solid rgba(255,255,255,0.1)', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '12px', color: '#fff' }}
              >
                <div style={{ width: '32px', height: '32px', borderRadius: '50%', backgroundColor: '#f43f5e', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: '700', fontSize: '12px', color: '#fff' }}>AM</div>
                <div>
                  <div style={{ fontSize: '12px', fontWeight: '600' }}>Dr. Aminah Macarambon</div>
                  <div style={{ fontSize: '11px', color: 'rgba(254,205,211,0.6)' }}>a.macarambon@msumain.edu.ph</div>
                </div>
              </button>
            </div>

            <button
              onClick={() => setIsGoogleModalOpen(false)}
              style={{ width: '100%', fontSize: '12px', color: 'rgba(254,205,211,0.5)', background: 'none', border: 'none', cursor: 'pointer', padding: '8px 0' }}
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
