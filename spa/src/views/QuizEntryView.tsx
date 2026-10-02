import React, { useEffect, useState } from 'react';
import { api, getServerTime } from '../services/api';
import { QuizStateResponse, StartAttemptResponse } from '../types';
import { Navbar } from '../components/Navbar';

interface QuizEntryViewProps {
  quizCode: string;
  onStartAttempt: (attempt: StartAttemptResponse) => void;
  onResumeAttempt: (attemptId: string) => void;
}

export const QuizEntryView: React.FC<QuizEntryViewProps> = ({
  quizCode,
  onStartAttempt,
  onResumeAttempt,
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
      setError(err?.message || 'Failed to load quiz information');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchState();
  }, [quizCode]);

  // Thundering herd courtesy timer: when window countdown reaches 0, jitter 0-8s before auto-fetch
  useEffect(() => {
    if (timeUntilOpenMs === null || timeUntilOpenMs <= 0) {
      return;
    }

    const interval = setInterval(() => {
      const diff = (data?.meta.opens_at_ms || 0) - getServerTime();
      if (diff <= 0) {
        clearInterval(interval);
        setTimeUntilOpenMs(0);
        // Add 0-8s random jitter to protect 2 vCPU server
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

  if (loading && !data) {
    return (
      <div>
        <Navbar />
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '60vh' }}>
          <div style={{ fontSize: '1.125rem', color: 'var(--gray-500)', fontWeight: 500 }}>
            Loading assessment details...
          </div>
        </div>
      </div>
    );
  }

  const meta = data?.meta;

  return (
    <div>
      <Navbar />

      <main style={{ maxWidth: '800px', margin: '2.5rem auto', padding: '0 1.5rem' }}>
        <div className="glass-card" style={{ padding: '2.5rem' }}>
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

          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '0.75rem' }}>
            <span style={{
              background: 'var(--primary-light)',
              color: 'var(--primary)',
              fontSize: '0.8125rem',
              fontWeight: 700,
              padding: '0.25rem 0.625rem',
              borderRadius: '6px',
            }}>
              {quizCode}
            </span>
            <span style={{ fontSize: '0.875rem', color: 'var(--gray-500)' }}>
              Online Certification
            </span>
          </div>

          <h2 style={{ fontSize: '1.875rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '1rem' }}>
            {meta?.title || 'Assessment Title'}
          </h2>

          {meta?.description && (
            <p style={{ fontSize: '1rem', color: 'var(--gray-600)', marginBottom: '1.5rem', lineHeight: 1.6 }}>
              {meta.description}
            </p>
          )}

          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
            gap: '1rem',
            marginBottom: '2rem',
            padding: '1.25rem',
            background: 'var(--gray-100)',
            borderRadius: '12px',
          }}>
            <div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 600, textTransform: 'uppercase' }}>
                Duration
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                {Math.round((meta?.duration_seconds || 0) / 60)} Minutes
              </div>
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 600, textTransform: 'uppercase' }}>
                Total Questions
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                {meta?.total_questions || 0} Questions
              </div>
            </div>

            <div>
              <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 600, textTransform: 'uppercase' }}>
                Attempts Allowed
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                1 Single Attempt
              </div>
            </div>
          </div>

          {meta?.instructions && (
            <div style={{ marginBottom: '2.5rem' }}>
              <h3 style={{ fontSize: '1rem', fontWeight: 700, color: 'var(--gray-800)', marginBottom: '0.75rem' }}>
                Rules & Instructions
              </h3>
              <div style={{
                background: '#ffffff',
                border: '1px solid var(--gray-200)',
                borderRadius: '10px',
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
          <div style={{ textAlign: 'center', paddingTop: '1rem' }}>
            {data?.state === 'not_open' && (
              <div style={{ padding: '1.5rem', background: 'var(--warning-light)', borderRadius: '12px' }}>
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
            )}

            {data?.state === 'closed' && (
              <div style={{ padding: '1.5rem', background: 'var(--gray-100)', borderRadius: '12px' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--gray-700)' }}>
                  Assessment Window Closed
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-500)', marginTop: '0.375rem' }}>
                  The examination window for this assessment has expired.
                </p>
              </div>
            )}

            {data?.state === 'ineligible' && (
              <div style={{ padding: '1.5rem', background: 'var(--danger-light)', borderRadius: '12px' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--danger)' }}>
                  Enrollment Required
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginTop: '0.375rem' }}>
                  Your employee profile is not enrolled in this assessment group.
                </p>
              </div>
            )}

            {data?.state === 'completed' && (
              <div style={{ padding: '1.5rem', background: 'var(--success-light)', borderRadius: '12px' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--success)' }}>
                  Assessment Already Completed
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginTop: '0.375rem' }}>
                  Your answers have been submitted and locked.
                </p>
                {data.attempt?.id && (
                  <button
                    onClick={handleResume}
                    className="btn btn-secondary"
                    style={{ marginTop: '1rem' }}
                  >
                    View Results
                  </button>
                )}
              </div>
            )}

            {data?.state === 'resumable' && (
              <div>
                <button
                  onClick={handleResume}
                  className="btn btn-primary"
                  style={{ padding: '1rem 2.5rem', fontSize: '1.125rem' }}
                >
                  Resume Assessment
                </button>
              </div>
            )}

            {data?.state === 'can_start' && (
              <div>
                <button
                  onClick={handleStart}
                  disabled={starting}
                  className="btn btn-primary"
                  style={{ padding: '1rem 3rem', fontSize: '1.125rem' }}
                >
                  {starting ? 'Starting Timer...' : 'I Am Ready — Start Assessment'}
                </button>
                <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', marginTop: '0.75rem' }}>
                  The timer begins as soon as you click Start.
                </div>
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  );
};
