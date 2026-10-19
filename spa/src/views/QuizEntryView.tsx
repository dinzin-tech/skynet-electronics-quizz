import React, { useEffect, useState } from 'react';
import { api, getServerTime, clearStoredAuth } from '../services/api';
import { QuizStateResponse, StartAttemptResponse } from '../types';
import { Navbar } from '../components/Navbar';

interface QuizEntryViewProps {
  quizCode: string;
  onSelectQuizCode?: (code: string) => void;
  onStartAttempt: (attempt: StartAttemptResponse) => void;
  onResumeAttempt: (attemptId: string) => void;
  onViewResult?: (attemptId: string) => void;
}

export const QuizEntryView: React.FC<QuizEntryViewProps> = ({
  quizCode,
  onStartAttempt,
  onResumeAttempt,
  onViewResult,
}) => {
  const [data, setData] = useState<QuizStateResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isNotEligible, setIsNotEligible] = useState(false);
  const [timeUntilOpenMs, setTimeUntilOpenMs] = useState<number | null>(null);

  const fetchState = async () => {
    if (!quizCode) {
      setLoading(false);
      return;
    }
    try {
      setLoading(true);
      setError(null);
      setIsNotEligible(false);
      const res = await api.getQuizMeta(quizCode);
      if (res.state === 'ineligible') {
        setIsNotEligible(true);
      }
      setData(res);

      if (res.state === 'not_open' && res.meta?.opens_at_ms) {
        const diff = res.meta.opens_at_ms - getServerTime();
        setTimeUntilOpenMs(Math.max(0, diff));
      } else {
        setTimeUntilOpenMs(null);
      }
    } catch (err: any) {
      if (
        err?.status === 403 ||
        err?.state === 'ineligible' ||
        (err?.message && (
          err.message.toLowerCase().includes('not enrolled') ||
          err.message.toLowerCase().includes('not eligible') ||
          err.message.toLowerCase().includes('ineligible')
        ))
      ) {
        setIsNotEligible(true);
      } else {
        setError(err?.message || 'Failed to load assessment details');
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchState();
  }, [quizCode]);

  useEffect(() => {
    if (timeUntilOpenMs === null || timeUntilOpenMs <= 0) {
      return;
    }

    const interval = setInterval(() => {
      const diff = (data?.meta.opens_at_ms || 0) - getServerTime();
      if (diff <= 0) {
        clearInterval(interval);
        setTimeUntilOpenMs(0);
        const jitterMs = Math.floor(Math.random() * 5000);
        setTimeout(() => {
          fetchState();
        }, jitterMs);
      } else {
        setTimeUntilOpenMs(diff);
      }
    }, 1000);

    return () => clearInterval(interval);
  }, [timeUntilOpenMs, data?.meta.opens_at_ms]);

  const handleStart = async () => {
    if (starting) return;
    setStarting(true);
    setError(null);
    try {
      const res = await api.startAttempt(quizCode);
      onStartAttempt(res);
    } catch (err: any) {
      setError(err?.message || 'Failed to start quiz');
      setStarting(false);
    }
  };

  const handleResume = () => {
    if (data?.attempt?.id) {
      onResumeAttempt(data.attempt.id);
    }
  };

  const handleViewResults = () => {
    if (data?.attempt?.id) {
      if (onViewResult) {
        onViewResult(data.attempt.id);
      } else {
        onResumeAttempt(data.attempt.id);
      }
    }
  };

  const formatDateTime = (ms?: number) => {
    if (!ms) return 'N/A';
    return new Date(ms).toLocaleString(undefined, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true,
    });
  };

  const formatTimeOnly = (ms?: number) => {
    if (!ms) return '';
    return new Date(ms).toLocaleTimeString(undefined, {
      hour: '2-digit',
      minute: '2-digit',
      hour12: true,
    });
  };

  if (loading && !data) {
    return (
      <div className="peak-app-bg" style={{ minHeight: '100dvh' }}>
        <Navbar hideBadges={true} />
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '60vh' }}>
          <div style={{ fontSize: '1.125rem', color: 'rgba(255, 255, 255, 0.7)', fontWeight: 600 }}>
            Loading assessment details...
          </div>
        </div>
      </div>
    );
  }

  if (isNotEligible || data?.state === 'ineligible') {
    return (
      <div className="peak-app-bg" style={{ minHeight: '100dvh', display: 'flex', flexDirection: 'column' }}>
        <Navbar hideBadges={true} />
        <main style={{ maxWidth: '560px', width: '100%', margin: '4rem auto', padding: '0 1.25rem', flex: 1 }}>
          <div className="glass-card-dark" style={{ padding: '3rem 2rem', textAlign: 'center' }}>
            <div style={{
              width: '64px',
              height: '64px',
              borderRadius: '50%',
              background: 'rgba(239, 68, 68, 0.15)',
              color: '#f87171',
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: '1.75rem',
              marginBottom: '1.5rem',
              border: '2px solid rgba(239, 68, 68, 0.4)',
            }}>
              🔒
            </div>

            <h2 style={{ fontSize: '1.5rem', fontWeight: 800, color: '#ffffff', marginBottom: '1rem', lineHeight: 1.4 }}>
              You are not eligible to take this quiz.
            </h2>

            <div style={{ display: 'flex', gap: '1rem', justifyContent: 'center', marginTop: '2.5rem', flexWrap: 'wrap' }}>
              <button
                type="button"
                onClick={() => {
                  if (window.history.length > 1) {
                    window.history.back();
                  } else {
                    window.location.href = '/';
                  }
                }}
                className="peak-submit-btn"
                style={{
                  padding: '0.85rem 2rem',
                  fontSize: '0.95rem',
                  maxWidth: '180px',
                  background: 'rgba(255, 255, 255, 0.08)',
                  border: '1px solid rgba(255, 255, 255, 0.2)',
                  color: '#ffffff',
                }}
              >
                Go Back
              </button>

              <button
                type="button"
                onClick={() => {
                  clearStoredAuth();
                  window.location.reload();
                }}
                className="peak-submit-btn"
                style={{
                  padding: '0.85rem 2rem',
                  fontSize: '0.95rem',
                  maxWidth: '180px',
                }}
              >
                Sign Out
              </button>
            </div>
          </div>
        </main>
      </div>
    );
  }

  const meta = data?.meta;
  const isTimeExpired = data?.state === 'closed' || (meta?.closes_at_ms ? getServerTime() >= meta.closes_at_ms : false);
  const isCompleted = data?.state === 'completed' || data?.attempt?.status === 'COMPLETED';
  const hasAttempt = !!data?.attempt?.id;
  const isContestLive = data?.state === 'can_start' || data?.state === 'resumable';
  const durationMinutes = Math.round((meta?.duration_seconds || 0) / 60);

  // Formatted Window String (e.g. "2:00 PM – 2:30 PM (12 Oct 2026)")
  const windowTimeString = meta?.opens_at_ms && meta?.closes_at_ms
    ? `${formatTimeOnly(meta.opens_at_ms)} – ${formatTimeOnly(meta.closes_at_ms)}`
    : meta?.opens_at_ms
    ? `Opens at ${formatDateTime(meta.opens_at_ms)}`
    : 'Open Assessment';

  return (
    <div className="peak-app-bg" style={{ minHeight: '100dvh', paddingBottom: '3rem' }}>
      <Navbar
        currentWeekLabel={data?.meta?.week_label}
        statusBadge={isContestLive ? 'CONTEST LIVE' : isCompleted ? 'COMPLETED' : undefined}
      />

      <main style={{ maxWidth: '960px', margin: '2rem auto', padding: '0 1.25rem' }}>
        <div className="glass-card-dark" style={{ padding: '2.5rem 2rem', position: 'relative' }}>
          {error && (
            <div style={{
              padding: '1rem',
              background: 'rgba(239, 68, 68, 0.2)',
              border: '1px solid rgba(239, 68, 68, 0.4)',
              color: '#fca5a5',
              borderRadius: '10px',
              fontSize: '0.875rem',
              fontWeight: 600,
              marginBottom: '1.5rem',
            }}>
              {error}
            </div>
          )}

          {/* Week & Assessment Label */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem', flexWrap: 'wrap' }}>
            <span style={{
              background: 'linear-gradient(135deg, #1f0508 0%, #3a080d 100%)',
              color: 'var(--gold-primary)',
              border: '1px solid var(--gold-primary)',
              fontSize: '0.8125rem',
              fontWeight: 800,
              padding: '0.35rem 0.75rem',
              borderRadius: '8px',
              letterSpacing: '0.05em',
            }}>
              {meta?.week_label || 'WEEKLY CHALLENGE'}
            </span>
            <span style={{ fontSize: '0.875rem', color: 'rgba(255, 255, 255, 0.65)', fontWeight: 600 }}>
              Assessment Code: {quizCode}
            </span>
          </div>

          {/* Assessment Title */}
          <h2 style={{ fontSize: '2rem', fontWeight: 800, color: '#ffffff', marginBottom: '1rem', lineHeight: 1.3 }}>
            {meta?.title || 'Certification Assessment'}
          </h2>

          {meta?.description && (
            <p style={{ fontSize: '1rem', color: 'rgba(255, 255, 255, 0.8)', marginBottom: '1.75rem', lineHeight: 1.6 }}>
              {meta.description}
            </p>
          )}

          {/* Assessment Key Stats */}
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
            gap: '1rem',
            marginBottom: '2rem',
            padding: '1.25rem',
            background: 'rgba(255, 255, 255, 0.04)',
            border: '1px solid rgba(245, 197, 66, 0.25)',
            borderRadius: '14px',
          }}>
            <div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.6)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Assessment Window
              </div>
              <div style={{ fontSize: '1.15rem', fontWeight: 800, color: 'var(--peak-gold-main)', marginTop: '0.25rem' }}>
                {windowTimeString}
              </div>
              {meta?.closes_at_ms && (
                <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.5)', marginTop: '0.15rem' }}>
                  {new Date(meta.closes_at_ms).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })}
                </div>
              )}
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.6)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Quiz Duration
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--peak-gold-main)', marginTop: '0.25rem' }}>
                {durationMinutes > 0 ? `${durationMinutes} Minutes` : `${meta?.duration_seconds || 0} Seconds`}
              </div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.5)', marginTop: '0.15rem' }}>
                Strict server-synced timer
              </div>
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.6)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Questions
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 800, color: '#ffffff', marginTop: '0.25rem' }}>
                {meta?.total_questions || 10} MCQs + Feedback
              </div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.5)', marginTop: '0.15rem' }}>
                All questions compulsory
              </div>
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.6)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Attempts
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--peak-gold-main)', marginTop: '0.25rem' }}>
                1 Single Attempt
              </div>
              <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.5)', marginTop: '0.15rem' }}>
                Locks on submission
              </div>
            </div>
          </div>

          {/* Instructions Box */}
          {meta?.instructions && (
            <div style={{ marginBottom: '2.5rem' }}>
              <h3 style={{ fontSize: '1rem', fontWeight: 800, color: '#ffffff', marginBottom: '0.75rem' }}>
                Examination Rules & Guidelines
              </h3>
              <div style={{
                background: 'rgba(255, 255, 255, 0.04)',
                border: '1px solid rgba(255, 255, 255, 0.12)',
                borderRadius: '12px',
                padding: '1.25rem',
                fontSize: '0.9375rem',
                color: 'rgba(255, 255, 255, 0.85)',
                whiteSpace: 'pre-line',
                lineHeight: 1.6,
              }}>
                {meta.instructions}
              </div>
            </div>
          )}

          {/* Action Gateway */}
          <div style={{ textAlign: 'center', paddingTop: '0.5rem' }}>
            {isCompleted ? (
              <div style={{
                padding: '1.75rem',
                background: 'rgba(16, 185, 129, 0.15)',
                borderRadius: '16px',
                border: '1.5px solid rgba(16, 185, 129, 0.4)',
              }}>
                <div style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '0.5rem',
                  fontSize: '1.125rem',
                  fontWeight: 800,
                  color: '#34d399',
                  marginBottom: '0.35rem',
                }}>
                  <span>✓</span>
                  <span>{isTimeExpired ? 'Quiz Window Expired — Assessment Completed' : 'Assessment Completed'}</span>
                </div>
                <p style={{ fontSize: '0.875rem', color: 'rgba(255, 255, 255, 0.8)', marginBottom: hasAttempt ? '1.25rem' : '0' }}>
                  {isTimeExpired 
                    ? 'The examination window has expired. Your answers were recorded and finalized.'
                    : 'Your answers have been submitted and locked.'}
                </p>
                {hasAttempt && (
                  <button
                    onClick={handleViewResults}
                    className="peak-submit-btn"
                    style={{ padding: '0.85rem 2.25rem', fontSize: '1rem', margin: '0 auto' }}
                  >
                    View Results
                  </button>
                )}
              </div>
            ) : isTimeExpired ? (
              <div style={{
                padding: '1.75rem',
                background: 'rgba(239, 68, 68, 0.15)',
                borderRadius: '16px',
                border: '1.5px solid rgba(239, 68, 68, 0.4)',
              }}>
                <div style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '0.5rem',
                  fontSize: '1.25rem',
                  fontWeight: 800,
                  color: '#f87171',
                  marginBottom: '0.35rem',
                }}>
                  <span>⏱</span>
                  <span>Assessment Window Closed</span>
                </div>
                <p style={{ fontSize: '0.875rem', color: 'rgba(255, 255, 255, 0.8)', marginTop: '0.25rem' }}>
                  The examination window for this challenge has closed.
                  {meta?.closes_at_ms ? ` (Ended: ${formatDateTime(meta.closes_at_ms)})` : ''}
                </p>
              </div>
            ) : data?.state === 'not_open' ? (
              <div style={{
                padding: '1.75rem',
                background: 'rgba(245, 158, 11, 0.15)',
                borderRadius: '16px',
                border: '1.5px solid rgba(245, 158, 11, 0.4)',
              }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 800, color: 'var(--peak-gold-main)' }}>
                  Assessment Window Opens Soon
                </div>
                {meta?.opens_at_ms && (
                  <div style={{ fontSize: '0.9375rem', color: 'rgba(255, 255, 255, 0.8)', marginTop: '0.35rem' }}>
                    Scheduled Start: {formatDateTime(meta.opens_at_ms)}
                  </div>
                )}
                {timeUntilOpenMs !== null && timeUntilOpenMs > 0 && (
                  <div style={{
                    fontSize: '2rem',
                    fontWeight: 900,
                    color: '#ffffff',
                    fontFamily: 'monospace',
                    marginTop: '0.5rem',
                    letterSpacing: '0.05em',
                  }}>
                    {Math.floor(timeUntilOpenMs / 60000)}m {Math.floor((timeUntilOpenMs % 60000) / 1000)}s
                  </div>
                )}
                <p style={{ fontSize: '0.8125rem', color: 'rgba(255, 255, 255, 0.65)', marginTop: '0.5rem' }}>
                  The portal will automatically activate when the window opens.
                </p>
              </div>
            ) : data?.state === 'resumable' ? (
              <div>
                <button
                  onClick={handleResume}
                  className="peak-submit-btn"
                  style={{ padding: '1rem 3rem', fontSize: '1.125rem', maxWidth: '380px', margin: '0 auto' }}
                >
                  Resume Assessment
                </button>
              </div>
            ) : data?.state === 'can_start' ? (
              <div>
                <button
                  onClick={handleStart}
                  disabled={starting}
                  className="peak-submit-btn"
                  style={{
                    padding: '1.1rem 3.5rem',
                    fontSize: '1.2rem',
                    maxWidth: '420px',
                    margin: '0 auto',
                    letterSpacing: '0.04em',
                  }}
                >
                  {starting ? 'Starting Timer...' : 'I Am Ready — Start Assessment'}
                </button>
                <div style={{ fontSize: '0.8125rem', color: 'rgba(255, 255, 255, 0.6)', marginTop: '0.75rem', fontWeight: 500 }}>
                  Assessment timer starts immediately upon clicking.
                </div>
              </div>
            ) : null}
          </div>
        </div>
      </main>
    </div>
  );
};
