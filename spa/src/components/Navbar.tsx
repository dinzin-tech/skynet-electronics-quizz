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
      background: 'linear-gradient(180deg, #ffffff 0%, #fafafa 100%)',
      borderBottom: '1px solid rgba(212, 175, 55, 0.3)',
      padding: '0.75rem 1.5rem',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      position: 'sticky',
      top: 0,
      zIndex: 40,
      boxShadow: '0 2px 10px rgba(0,0,0,0.04)',
    }} className="navbar-container">
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.875rem' }}>
        <img 
          src="/app/logo.png" 
          alt="PEAK PURSUIT 4.0" 
          className="brand-logo-img"
          onError={(e) => {
            // Fallback path if loaded under different routing
            (e.target as HTMLImageElement).src = '/logo.png';
          }}
        />
        <div>
          <h1 className="navbar-title" style={{ 
            fontSize: '1.0625rem', 
            fontWeight: 800, 
            background: 'linear-gradient(135deg, #1a0508 0%, #8b0000 100%)',
            WebkitBackgroundClip: 'text',
            WebkitTextFillColor: 'transparent',
            letterSpacing: '-0.01em',
          }}>
            PEAK PURSUIT 4.0
          </h1>
          <span className="navbar-subtitle" style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 600, display: 'block' }}>
            Enterprise Assessment Portal
          </span>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
        {children}

        {user && (
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <div className="user-info-text" style={{ textAlign: 'right' }}>
              <div style={{ fontSize: '0.875rem', fontWeight: 700, color: 'var(--gray-900)' }}>
                {user.name}
              </div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gold-primary)', fontWeight: 600 }}>
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

