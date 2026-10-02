import React, { useState } from 'react';
import { api } from '../services/api';

interface LoginViewProps {
  onLoginSuccess: () => void;
  defaultQuizCode?: string;
}

export const LoginView: React.FC<LoginViewProps> = ({ onLoginSuccess, defaultQuizCode }) => {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!login.trim() || !password) {
      setError('Please provide your Employee Code/Email and password');
      return;
    }

    setError(null);
    setLoading(true);

    try {
      await api.login(login.trim(), password);
      onLoginSuccess();
    } catch (err: any) {
      setError(err?.message || 'Login failed. Please verify credentials.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{
      minHeight: '100vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '1.5rem',
      background: 'linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%)',
    }}>
      <div className="glass-card" style={{ width: '100%', maxWidth: '440px', padding: '2.5rem' }}>
        <div style={{ textAlign: 'center', marginBottom: '2rem' }}>
          <div style={{
            width: '48px',
            height: '48px',
            borderRadius: '12px',
            background: 'var(--primary)',
            color: '#ffffff',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontWeight: 800,
            fontSize: '1.5rem',
            marginBottom: '1rem',
            boxShadow: '0 4px 14px rgba(79, 70, 229, 0.4)',
          }}>
            Q
          </div>
          <h2 style={{ fontSize: '1.5rem', fontWeight: 800, color: 'var(--gray-900)' }}>
            Corporate Assessment Portal
          </h2>
          <p style={{ fontSize: '0.875rem', color: 'var(--gray-500)', marginTop: '0.375rem' }}>
            Sign in to start or resume your assigned assessment
          </p>
        </div>

        {error && (
          <div style={{
            padding: '0.75rem 1rem',
            background: 'var(--danger-light)',
            color: 'var(--danger)',
            borderRadius: '10px',
            fontSize: '0.875rem',
            fontWeight: 500,
            marginBottom: '1.5rem',
            border: '1px solid rgba(239, 68, 68, 0.2)',
          }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          <div>
            <label style={{ display: 'block', fontSize: '0.875rem', fontWeight: 600, color: 'var(--gray-700)', marginBottom: '0.375rem' }}>
              Employee Identifier
            </label>
            <input
              type="text"
              className="form-input"
              placeholder="e.g. EMP0001 or email@corp.local"
              value={login}
              onChange={(e) => setLogin(e.target.value)}
              disabled={loading}
              autoFocus
            />
          </div>

          <div>
            <label style={{ display: 'block', fontSize: '0.875rem', fontWeight: 600, color: 'var(--gray-700)', marginBottom: '0.375rem' }}>
              Password
            </label>
            <input
              type="password"
              className="form-input"
              placeholder="••••••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              disabled={loading}
            />
          </div>

          <button
            type="submit"
            className="btn btn-primary"
            style={{ width: '100%', padding: '0.875rem', marginTop: '0.5rem' }}
            disabled={loading}
          >
            {loading ? 'Authenticating...' : 'Sign In to Assessment'}
          </button>
        </form>

        {defaultQuizCode && (
          <div style={{ marginTop: '1.5rem', textAlign: 'center', fontSize: '0.8125rem', color: 'var(--gray-500)' }}>
            Direct access link for quiz: <strong style={{ color: 'var(--gray-700)' }}>{defaultQuizCode}</strong>
          </div>
        )}
      </div>
    </div>
  );
};
