import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ROUTE_BY_ROLE } from './Dashboard';
import { useAuth } from '../context/AuthContext';

// Map the role code returned by the backend to its dashboard URL.
function getDashboardPath(roleCode) {
  return ROUTE_BY_ROLE[roleCode] || '/dashboard';
}

export default function Login() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');

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

            <p style={{ textAlign: 'center', fontSize: '12px', color: 'rgba(254, 205, 211, 0.6)', marginTop: '20px' }}>
              Don't have an account?{' '}
              <Link to="/register" style={{ color: '#fbbf24', fontWeight: '600', textDecoration: 'underline' }}>
                Register here
              </Link>
            </p>
          </div>
        </div>
      </div>

    </div>
  );
}
