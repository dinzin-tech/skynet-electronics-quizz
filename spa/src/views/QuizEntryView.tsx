import React, { useEffect, useState } from 'react';
import { api, getServerTime } from '../services/api';
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
  const [timeUntilOpenMs, setTimeUntilOpenMs] = useState<number | null>(null);

  const fetchState = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await api.getQuizMeta(quizCode);
      setData(res);

      if (res.state === 'not_open' && res.meta.opens_at_ms) {
        const diff = res.meta.opens_at_ms - getServerTime();
        setTimeUntilOpenMs(Math.max(0, diff));
      } else {
        setTimeUntilOpenMs(null);
      }
    } catch (err: any) {
      setError(err?.message || 'Failed to load assessment details');
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
        const jitterMs = Math.floor(Math.random() * 8000);
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
    if (starting) {
      return;
    }
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

  const formatDate = (ms?: number) => {
    if (!ms) return '';
    return new Date(ms).toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  if (loading && !data) {
    return (
      <div>
        <Navbar />
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '60vh' }}>
          <div style={{ fontSize: '1.125rem', color: 'var(--gray-500)', fontWeight: 600 }}>
            Loading assessment details...
          </div>
        </div>
      </div>
    );
  }

  const meta = data?.meta;
  const isTimeExpired = data?.state === 'closed' || (meta?.closes_at_ms ? getServerTime() >= meta.closes_at_ms : false);
  const isCompleted = data?.state === 'completed' || data?.attempt?.status === 'COMPLETED';
  const hasAttempt = !!data?.attempt?.id;

  return (
    <div style={{ minHeight: '100dvh', background: 'var(--gray-50)', paddingBottom: '3rem' }}>
      <Navbar />

      <main style={{ maxWidth: '960px', margin: '2rem auto', padding: '0 1.25rem' }}>
        {/* Selected Assessment Hero Card */}
        <div className="glass-card" style={{ padding: '2.5rem 2rem' }}>
          {error && (
            <div style={{
              padding: '1rem',
              background: 'var(--danger-light)',
              color: 'var(--danger)',
              borderRadius: '10px',
              fontSize: '0.875rem',
              fontWeight: 600,
              marginBottom: '1.5rem',
            }}>
              {error}
            </div>
          )}

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
              {quizCode}
            </span>
            <span style={{ fontSize: '0.875rem', color: 'var(--gray-500)', fontWeight: 600 }}>
              Selected Certification Details
            </span>
          </div>

          <h2 style={{ fontSize: '1.875rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '1rem', lineHeight: 1.3 }}>
            {meta?.title || 'Assessment Title'}
          </h2>

          {meta?.description && (
            <p style={{ fontSize: '1rem', color: 'var(--gray-600)', marginBottom: '1.75rem', lineHeight: 1.6 }}>
              {meta.description}
            </p>
          )}

          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
            gap: '1rem',
            marginBottom: '2rem',
            padding: '1.25rem',
            background: 'linear-gradient(135deg, #fafafa 0%, #f1f5f9 100%)',
            border: '1px solid var(--gray-200)',
            borderRadius: '14px',
          }}>
            <div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.03em' }}>
                Duration
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--primary)', marginTop: '0.25rem' }}>
                {Math.round((meta?.duration_seconds || 0) / 60)} Minutes
              </div>
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.03em' }}>
                Total Questions
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                {meta?.total_questions || 0} Questions
              </div>
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.03em' }}>
                Attempts Allowed
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--gold-primary)', marginTop: '0.25rem' }}>
                1 Single Attempt
              </div>
            </div>
          </div>

          {meta?.instructions && (
            <div style={{ marginBottom: '2.5rem' }}>
              <h3 style={{ fontSize: '1rem', fontWeight: 800, color: 'var(--gray-800)', marginBottom: '0.75rem' }}>
                Rules & Examination Guidelines
              </h3>
              <div style={{
                background: '#ffffff',
                border: '1.5px solid var(--gray-200)',
                borderRadius: '12px',
                padding: '1.25rem',
                fontSize: '0.9375rem',
                color: 'var(--gray-700)',
                whiteSpace: 'pre-line',
                lineHeight: 1.6,
              }}>
                {meta.instructions}
              </div>
            </div>
          )}

          {/* Action states */}
          <div style={{ textAlign: 'center', paddingTop: '0.5rem' }}>
            {isCompleted ? (
              <div style={{
                padding: '1.75rem',
                background: 'var(--success-light)',
                borderRadius: '16px',
                border: '1.5px solid rgba(16, 185, 129, 0.3)',
              }}>
                <div style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '0.5rem',
                  fontSize: '1.125rem',
                  fontWeight: 800,
                  color: 'var(--success)',
                  marginBottom: '0.35rem',
                }}>
                  <span>✓</span>
                  <span>{isTimeExpired ? 'Quiz Time Expired — Assessment Completed' : 'Assessment Already Completed'}</span>
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginBottom: hasAttempt ? '1.25rem' : '0' }}>
                  {isTimeExpired 
                    ? 'The examination window has expired. Your answers were recorded and finalized.'
                    : 'Your answers have been submitted and locked.'}
                </p>
                {hasAttempt && (
                  <button
                    onClick={handleViewResults}
                    className="btn btn-primary"
                    style={{ padding: '0.85rem 2rem', fontSize: '1rem' }}
                  >
                    📊 View Results
                  </button>
                )}
              </div>
            ) : isTimeExpired ? (
              <div style={{
                padding: '1.75rem',
                background: 'var(--danger-light)',
                borderRadius: '16px',
                border: '1.5px solid rgba(239, 68, 68, 0.25)',
              }}>
                <div style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '0.5rem',
                  fontSize: '1.25rem',
                  fontWeight: 800,
                  color: 'var(--danger)',
                  marginBottom: '0.35rem',
                }}>
                  <span>⏱</span>
                  <span>Quiz Time Expired</span>
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginTop: '0.25rem' }}>
                  The examination window for this assessment has expired.
                  {meta?.closes_at_ms ? ` (Closed: ${formatDate(meta.closes_at_ms)})` : ''}
                </p>
              </div>
            ) : data?.state === 'not_open' ? (
              <div style={{ padding: '1.5rem', background: 'var(--warning-light)', borderRadius: '14px', border: '1px solid rgba(245, 158, 11, 0.3)' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--warning)' }}>
                  Assessment Window Opens Soon
                </div>
                {timeUntilOpenMs !== null && timeUntilOpenMs > 0 && (
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--gray-900)', marginTop: '0.5rem' }}>
                    {Math.floor(timeUntilOpenMs / 60000)}m {Math.floor((timeUntilOpenMs % 60000) / 1000)}s
                  </div>
                )}
                <p style={{ fontSize: '0.8125rem', color: 'var(--gray-600)', marginTop: '0.5rem' }}>
                  The portal will automatically activate when the window opens.
                </p>
              </div>
            ) : data?.state === 'ineligible' ? (
              <div style={{ padding: '1.5rem', background: 'var(--danger-light)', borderRadius: '14px' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--danger)' }}>
                  Enrollment Required
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginTop: '0.375rem' }}>
                  Your employee profile is not enrolled in this assessment group.
                </p>
              </div>
            ) : data?.state === 'resumable' ? (
              <div>
                <button
                  onClick={handleResume}
                  className="btn btn-primary"
                  style={{ padding: '1rem 2.5rem', fontSize: '1.125rem', width: '100%', maxWidth: '360px' }}
                >
                  Resume Assessment
                </button>
              </div>
            ) : data?.state === 'can_start' ? (
              <div>
                <button
                  onClick={handleStart}
                  disabled={starting}
                  className="btn btn-primary"
                  style={{ padding: '1rem 2.5rem', fontSize: '1.125rem', width: '100%', maxWidth: '400px' }}
                >
                  {starting ? 'Starting Timer...' : 'I Am Ready — Start Assessment'}
                </button>
                <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', marginTop: '0.75rem', fontWeight: 500 }}>
                  The timer begins as soon as you click Start.
                </div>
              </div>
            ) : null}
          </div>
        </div>
      </main>
    </div>
  );
};
