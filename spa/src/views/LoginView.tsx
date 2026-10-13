import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { ProgrammeInfo } from '../types';
import { BrandBanner } from '../components/BrandBanner';

interface LoginViewProps {
  onLoginSuccess: () => void;
  defaultQuizCode?: string;
}

export const LoginView: React.FC<LoginViewProps> = ({ onLoginSuccess, defaultQuizCode }) => {
  const [empId, setEmpId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [programme, setProgramme] = useState<ProgrammeInfo | null>(null);

  // Fetch dynamic programme info & schedule on mount
  useEffect(() => {
    let isMounted = true;
    api.getProgrammeInfo(defaultQuizCode)
      .then((data) => {
        if (isMounted) {
          setProgramme(data);
        }
      })
      .catch((err) => {
        console.warn('Programme info could not be fetched:', err);
      });
    return () => {
      isMounted = false;
    };
  }, [defaultQuizCode]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const sanitizedId = empId.trim();

    if (!sanitizedId) {
      setError('Please enter your Employee ID');
      return;
    }

    setError(null);
    setLoading(true);

    try {
      // Backend handles case-insensitivity and preserves leading zeroes
      await api.login(sanitizedId);
      onLoginSuccess();
    } catch (err: any) {
      setError(
        err?.message ||
          'Verification failed. Please check your Employee ID or contact HR support.'
      );
    } finally {
      setLoading(false);
    }
  };

  const totalWeeks = programme?.total_weeks || 5;

  // Identify targeted week: match defaultQuizCode if present, otherwise fall back to programme.current_week
  const matchedWeek = defaultQuizCode && programme?.weeks?.find(
    (w) => w.code && w.code.toUpperCase() === defaultQuizCode.toUpperCase()
  );
  const currentWeek = matchedWeek ? matchedWeek.week_number : (programme?.current_week || 1);
  const currentWeekLabel = matchedWeek
    ? `WEEK - ${currentWeek}`
    : (programme?.current_week_label || `WEEK - ${currentWeek}`);
  const statusBadge = programme?.status_badge || 'CONTEST LIVE';
  const durationTagline =
    programme?.duration_tagline ||
    'Five weeks. Five knowledge areas. One chance each week to raise your game.';

  const weeksList = (programme?.weeks || Array.from({ length: totalWeeks }, (_, i) => ({
    week_number: i + 1,
    label: String(i + 1).padStart(2, '0'),
    status: (i + 1 === currentWeek ? 'active' : i + 1 < currentWeek ? 'completed' : 'upcoming') as 'active' | 'completed' | 'upcoming',
    title: `Week ${i + 1}`,
    code: null,
  }))).map((w) => {
    if (w.week_number === currentWeek) {
      return { ...w, status: 'active' as const };
    }
    if (w.week_number < currentWeek) {
      return { ...w, status: 'completed' as const };
    }
    return w;
  });

  return (
    <div className="peak-portal-wrapper">
      {/* 1. Full-Width Wide Top Horizontal Banner */}
      <BrandBanner
        currentWeekLabel={currentWeekLabel}
        totalWeeks={totalWeeks}
        statusBadge={statusBadge}
      />

      {/* 2. Main Hero + Login Area */}
      <main className="peak-portal-main">
        <div className="peak-portal-container">
          {/* Left Column: Campaign Hero & Timeline */}
          <section className="peak-hero-section">
            <div className="peak-hero-eyebrow">
              OPPO KARNATAKA &bull; PEAK PURSUIT
            </div>

            <h1 className="peak-hero-title">
              Your next level starts <span className="peak-gold-text">here.</span>
            </h1>

            <p className="peak-hero-subtitle">
              {durationTagline}
            </p>

            {/* Dynamic Week Timeline Milestone Bar */}
            <div className="peak-timeline-container">
              <div className="peak-timeline-track">
                {weeksList.map((w) => {
                  const isActive = w.week_number === currentWeek;
                  const isCompleted = w.status === 'completed';
                  return (
                    <div
                      key={w.week_number}
                      className={`peak-timeline-item ${
                        isActive ? 'is-active' : isCompleted ? 'is-completed' : 'is-upcoming'
                      }`}
                    >
                      <div className="timeline-week-tag">WEEK</div>
                      <div className="timeline-week-num">{w.label}</div>
                      <div className="timeline-status-dot"></div>
                    </div>
                  );
                })}
              </div>
            </div>

            {defaultQuizCode && (
              <div className="peak-quizcode-indicator">
                <span className="quizcode-label">Active Assessment Code:</span>
                <span className="quizcode-val">{defaultQuizCode}</span>
              </div>
            )}
          </section>

          {/* Right Column: Employee Access Card */}
          <section className="peak-access-card-wrapper">
            <div className="peak-access-card">
              <div className="access-card-badge">EMPLOYEE ACCESS</div>
              <h2 className="access-card-title">Ready to rise?</h2>
              <p className="access-card-desc">
                Enter your employee ID to get started.
              </p>

              {error && (
                <div className="peak-form-alert" role="alert">
                  <svg className="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                    <path
                      fillRule="evenodd"
                      d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                      clipRule="evenodd"
                    />
                  </svg>
                  <span>{error}</span>
                </div>
              )}

              <form onSubmit={handleSubmit} className="peak-login-form" noValidate>
                <div className="form-group-custom">
                  <label htmlFor="empIdInput" className="form-label-custom">
                    EMP ID
                  </label>
                  <input
                    id="empIdInput"
                    type="text"
                    className="form-input-custom"
                    placeholder="Enter your EMP ID"
                    value={empId}
                    onChange={(e) => setEmpId(e.target.value)}
                    disabled={loading}
                    autoFocus
                    autoComplete="username"
                    spellCheck="false"
                  />
                </div>

                <button
                  type="submit"
                  className="peak-btn-gold"
                  disabled={loading}
                >
                  {loading ? (
                    <>
                      <span className="peak-spinner"></span>
                      <span>Verifying...</span>
                    </>
                  ) : (
                    <>
                      <span>Enter Peak Pursuit</span>
                      <span className="btn-arrow">&rarr;</span>
                    </>
                  )}
                </button>
              </form>

              {/* Helper Guidelines */}
              <div className="access-card-help-note">
                Use the employee ID provided by HR, including any leading zeroes. Uppercase and lowercase both work. Need help? Contact your HR team.
              </div>
            </div>
          </section>
        </div>
      </main>

      {/* 3. Bottom Tagline Footer */}
      <footer className="peak-portal-footer">
        PEAK PURSUIT &bull; LEARN. COMPETE. RISE.
      </footer>
    </div>
  );
};
