import React, { useEffect, useState } from 'react';
import { api } from '../services/api';
import { AttemptStateResponse } from '../types';
import { Navbar } from '../components/Navbar';

interface ResultViewProps {
  attemptId: string;
  onDone: () => void;
}

export const ResultView: React.FC<ResultViewProps> = ({ attemptId, onDone }) => {
  const [attempt, setAttempt] = useState<AttemptStateResponse | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchAttempt = async () => {
    try {
      const data = await api.getAttempt(attemptId);
      setAttempt(data);
      if (data.status === 'COMPLETED' && data.result) {
        setLoading(false);
      } else {
        // Asynchronous finalizer is running; poll in 1.5 seconds
        setTimeout(fetchAttempt, 1500);
      }
    } catch {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchAttempt();
  }, [attemptId]);

  const result = attempt?.result;

  return (
    <div className="peak-app-bg" style={{ minHeight: '100dvh', display: 'flex', flexDirection: 'column' }}>
      <Navbar onLogout={onDone} statusBadge="COMPLETED" />

      <main style={{ maxWidth: '720px', width: '100%', margin: '2.5rem auto', padding: '0 1.25rem', flex: 1 }}>
        <div className="glass-card-dark" style={{ padding: '2.5rem 2rem', textAlign: 'center' }}>
          {/* Success Check Badge */}
          <div style={{
            width: '72px',
            height: '72px',
            borderRadius: '50%',
            background: 'rgba(16, 185, 129, 0.15)',
            color: '#34d399',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: '2.25rem',
            marginBottom: '1.25rem',
            border: '2px solid rgba(16, 185, 129, 0.4)',
            boxShadow: '0 0 24px rgba(16, 185, 129, 0.3)',
          }}>
            ✓
          </div>

          <h2 style={{ fontSize: '1.875rem', fontWeight: 800, color: '#ffffff', marginBottom: '0.5rem' }}>
            Assessment Completed
          </h2>
          <p style={{ fontSize: '0.95rem', color: 'rgba(255, 255, 255, 0.75)', marginBottom: '2rem' }}>
            Your assessment and feedback have been securely recorded under OPPO PEAK PURSUIT 4.0.
          </p>

          {loading || !result ? (
            <div style={{ padding: '2rem 1rem' }}>
              <div style={{ fontSize: '1rem', fontWeight: 700, color: 'var(--peak-gold-main)', marginBottom: '0.5rem' }}>
                Finalizing score calculations...
              </div>
              <div style={{ fontSize: '0.8125rem', color: 'rgba(255, 255, 255, 0.6)' }}>
                Please wait a moment while the asynchronous grading engine computes your results.
              </div>
            </div>
          ) : (
            <div>
              {/* Performance Cards */}
              <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
                gap: '1rem',
                marginBottom: '2rem',
              }}>
                <div style={{
                  padding: '1.25rem',
                  background: 'linear-gradient(135deg, rgba(245, 197, 66, 0.12) 0%, rgba(200, 150, 40, 0.06) 100%)',
                  border: '1.5px solid rgba(245, 197, 66, 0.4)',
                  borderRadius: '14px',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 800, color: 'var(--peak-gold-main)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Score
                  </div>
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: '#ffffff', marginTop: '0.25rem' }}>
                    {result.score} pts
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'rgba(255, 255, 255, 0.04)',
                  borderRadius: '14px',
                  border: '1px solid rgba(255, 255, 255, 0.12)',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 700, color: 'rgba(255, 255, 255, 0.6)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Accuracy
                  </div>
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: '#34d399', marginTop: '0.25rem' }}>
                    {result.accuracy}%
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'rgba(255, 255, 255, 0.04)',
                  borderRadius: '14px',
                  border: '1px solid rgba(255, 255, 255, 0.12)',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 700, color: 'rgba(255, 255, 255, 0.6)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Correct
                  </div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 800, color: '#ffffff', marginTop: '0.25rem' }}>
                    {result.correct_count} / {result.total_questions}
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'rgba(255, 255, 255, 0.04)',
                  borderRadius: '14px',
                  border: '1px solid rgba(255, 255, 255, 0.12)',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 700, color: 'rgba(255, 255, 255, 0.6)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Time Taken
                  </div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 800, color: 'var(--peak-gold-main)', marginTop: '0.25rem' }}>
                    {Math.floor(result.completion_time_s / 60)}m {result.completion_time_s % 60}s
                  </div>
                </div>
              </div>

              {/* Recorded Feedback Card */}
              {attempt?.feedback && (
                <div style={{
                  background: 'rgba(255, 255, 255, 0.04)',
                  border: '1px solid rgba(245, 197, 66, 0.25)',
                  borderRadius: '14px',
                  padding: '1.25rem 1.5rem',
                  textAlign: 'left',
                  marginBottom: '2rem',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                    <h4 style={{ fontSize: '0.9375rem', fontWeight: 700, color: '#ffffff', margin: 0, display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                      <span>💬</span> Your Feedback Response
                    </h4>
                    <span style={{
                      fontSize: '0.75rem',
                      fontWeight: 700,
                      color: '#34d399',
                      background: 'rgba(16, 185, 129, 0.2)',
                      padding: '2px 8px',
                      borderRadius: '12px',
                    }}>
                      ✓ Recorded
                    </span>
                  </div>
                  <div style={{
                    background: 'rgba(0, 0, 0, 0.35)',
                    border: '1px solid rgba(255, 255, 255, 0.08)',
                    borderRadius: '8px',
                    padding: '0.75rem 1rem',
                    fontSize: '0.9rem',
                    color: 'rgba(255, 255, 255, 0.85)',
                    lineHeight: '1.5',
                    whiteSpace: 'pre-wrap',
                  }}>
                    {attempt.feedback}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Return / Done Action */}
          <div style={{ marginTop: '1.5rem' }}>
            <button
              onClick={onDone}
              className="peak-submit-btn"
              style={{
                padding: '0.85rem 2.5rem',
                fontSize: '1rem',
                maxWidth: '300px',
                margin: '0 auto',
              }}
            >
              Back to Overview
            </button>
          </div>
        </div>
      </main>
    </div>
  );
};
