import React from 'react';
import { getStoredUser, clearStoredAuth } from '../services/api';

interface NavbarProps {
  onLogout?: () => void;
  children?: React.ReactNode;
}

export const Navbar: React.FC<NavbarProps> = ({ onLogout, children }) => {
  const user = getStoredUser();

  const handleLogout = () => {
    clearStoredAuth();
    if (onLogout) {
      onLogout();
    } else {
      window.location.reload();
    }
  };

  return (
    <header style={{
      background: '#ffffff',
      borderBottom: '1px solid var(--gray-200)',
      padding: '0.875rem 1.5rem',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      position: 'sticky',
      top: 0,
      zIndex: 40,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
        <div style={{
          width: '36px',
          height: '36px',
          borderRadius: '8px',
          background: 'var(--primary)',
          color: '#ffffff',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          fontWeight: 800,
          fontSize: '1.125rem'
        }}>
          Q
        </div>
        <div>
          <h1 style={{ fontSize: '1.0625rem', fontWeight: 700, color: 'var(--gray-900)' }}>
            Enterprise Assessment
          </h1>
          <span style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 500 }}>
            Secure Employee Examination Portal
          </span>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: '1.25rem' }}>
        {children}

        {user && (
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.875rem' }}>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: '0.875rem', fontWeight: 600, color: 'var(--gray-800)' }}>
                {user.name}
              </div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)' }}>
                {user.employee_code}
              </div>
            </div>
            <button
              onClick={handleLogout}
              className="btn btn-secondary"
              style={{ padding: '0.375rem 0.75rem', fontSize: '0.8125rem' }}
            >
              Sign Out
            </button>
          </div>
        )}
      </div>
    </header>
  );
};
