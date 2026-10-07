import React, { useState } from 'react';
import { api } from '../services/api';

interface LoginViewProps {
  onLoginSuccess: () => void;
  defaultQuizCode?: string;
}

export const LoginView: React.FC<LoginViewProps> = ({ onLoginSuccess, defaultQuizCode }) => {
  const [login, setLogin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!login.trim()) {
      setError('Please enter your Employee Code');
      return;
    }

    setError(null);
    setLoading(true);

    try {
      await api.login(login.trim());
      onLoginSuccess();
    } catch (err: any) {
      setError(err?.message || 'Login failed. Please verify your employee code.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{
      minHeight: '100dvh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '1.25rem',
      background: 'linear-gradient(135deg, #120306 0%, #2b080c 45%, #150204 100%)',
      position: 'relative',
      overflow: 'hidden',
    }}>
      {/* Subtle background glow element */}
      <div style={{
        position: 'absolute',
        width: '500px',
        height: '500px',
        borderRadius: '50%',
        background: 'radial-gradient(circle, rgba(212, 175, 55, 0.12) 0%, rgba(139, 0, 0, 0) 70%)',
        top: '-100px',
        right: '-100px',
        pointerEvents: 'none',
      }} />

      <div className="glass-card-dark" style={{ width: '100%', maxWidth: '440px', padding: '2.5rem 2rem' }}>
        <div style={{ textAlign: 'center', marginBottom: '1.75rem' }}>
          <img
            src="/app/logo.png"
            alt="PEAK PURSUIT 4.0"
            className="login-logo-img"
            onError={(e) => {
              (e.target as HTMLImageElement).src = '/logo.png';
            }}
          />
          <h2 style={{
            fontSize: '1.375rem',
            fontWeight: 800,
            background: 'var(--gold-gradient)',
            WebkitBackgroundClip: 'text',
            WebkitTextFillColor: 'transparent',
            letterSpacing: '0.02em',
          }}>
            EMPLOYEE ASSESSMENT PORTAL
          </h2>
          <p style={{ fontSize: '0.8125rem', color: 'rgba(255,255,255,0.7)', marginTop: '0.375rem' }}>
            Enter your employee code to start your assessment
          </p>
        </div>

        {error && (
          <div style={{
            padding: '0.75rem 1rem',
            background: 'rgba(239, 68, 68, 0.15)',
            color: '#fca5a5',
            borderRadius: '10px',
            fontSize: '0.875rem',
            fontWeight: 500,
            marginBottom: '1.5rem',
            border: '1px solid rgba(239, 68, 68, 0.3)',
          }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          <div>
            <label style={{ display: 'block', fontSize: '0.8125rem', fontWeight: 700, color: 'var(--gold-primary)', marginBottom: '0.375rem', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
              Employee Code
            </label>
            <input
              type="text"
              className="form-input"
              style={{ background: 'rgba(255,255,255,0.95)', border: '1px solid rgba(212, 175, 55, 0.3)' }}
              placeholder="e.g. EMP0001"
              value={login}
              onChange={(e) => setLogin(e.target.value)}
              disabled={loading}
              autoFocus
            />
          </div>

          <button
            type="submit"
            className="btn btn-primary"
            style={{ width: '100%', padding: '0.875rem', marginTop: '0.5rem', fontSize: '1rem' }}
            disabled={loading}
          >
            {loading ? 'Verifying...' : 'Sign In to Assessment'}
          </button>
        </form>

        {defaultQuizCode && (
          <div style={{ marginTop: '1.75rem', textAlign: 'center', fontSize: '0.8125rem', color: 'rgba(255,255,255,0.6)' }}>
            <p>Quiz Code: <strong style={{ color: 'var(--gold-primary)' }}>{defaultQuizCode}</strong></p>
            <p style={{ fontSize: '0.8125rem', color: 'rgba(255,255,255,0.6)', marginTop: '0.375rem' }}>Enter your employee code to start</p>
          </div>
        )}
      </div>
    </div>
  );
};

