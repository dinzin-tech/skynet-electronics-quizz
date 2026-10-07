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
    <div style={{ minHeight: '100dvh', background: 'var(--gray-50)', display: 'flex', flexDirection: 'column' }}>
      <Navbar onLogout={onDone} />

      <main style={{ maxWidth: '680px', width: '100%', margin: '2rem auto', padding: '0 1.25rem', flex: 1 }}>
        <div className="glass-card" style={{ padding: '2.5rem 1.75rem', textAlign: 'center' }}>
          <div style={{
            width: '68px',
            height: '68px',
            borderRadius: '50%',
            background: 'var(--success-light)',
            color: 'var(--success)',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: '2.25rem',
            marginBottom: '1.25rem',
            border: '2px solid rgba(16, 185, 129, 0.3)',
          }}>
            ✓
          </div>

          <h2 style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '0.5rem' }}>
            Assessment Completed
          </h2>
          <p style={{ fontSize: '0.9375rem', color: 'var(--gray-600)', marginBottom: '2rem' }}>
            Your assessment has been securely submitted and recorded under PEAK PURSUIT 4.0.
          </p>

          {loading || !result ? (
            <div style={{ padding: '2rem 1rem' }}>
              <div style={{ fontSize: '1rem', fontWeight: 700, color: 'var(--primary)', marginBottom: '0.5rem' }}>
                Finalizing score calculations...
              </div>
              <div style={{ fontSize: '0.8125rem', color: 'var(--gray-500)' }}>
                Please wait a moment while the asynchronous grading engine computes your results.
              </div>
            </div>
          ) : (
            <div>
              <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
                gap: '1rem',
                marginBottom: '2rem',
              }}>
                <div style={{
                  padding: '1.25rem',
                  background: 'linear-gradient(135deg, #fffdf0 0%, #fafafa 100%)',
                  border: '1.5px solid var(--gold-primary)',
                  borderRadius: '14px',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 800, color: 'var(--gold-primary)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Final Score
                  </div>
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--primary)', marginTop: '0.25rem' }}>
                    {result.score} pts
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '14px',
                  border: '1px solid var(--gray-200)',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 700, color: 'var(--gray-500)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Accuracy
                  </div>
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--success)', marginTop: '0.25rem' }}>
                    {result.accuracy}%
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '14px',
                  border: '1px solid var(--gray-200)',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 700, color: 'var(--gray-500)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Correct Answers
                  </div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                    {result.correct_count} / {result.total_questions}
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '14px',
                  border: '1px solid var(--gray-200)',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 700, color: 'var(--gray-500)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    Time Taken
                  </div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                    {Math.floor(result.completion_time_s / 60)}m {result.completion_time_s % 60}s
                  </div>
                </div>
              </div>

              {/* Recorded Feedback Display */}
              {attempt?.feedback && (
                <div style={{
                  background: '#ffffff',
                  border: '1px solid var(--gray-200)',
                  borderRadius: '14px',
                  padding: '1.25rem 1.5rem',
                  textAlign: 'left',
                  marginBottom: '2rem',
                  boxShadow: 'var(--shadow-sm)',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                    <h4 style={{ fontSize: '0.9375rem', fontWeight: 700, color: 'var(--gray-900)', margin: 0, display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                      <span>💬</span> Your Feedback
                    </h4>
                    <span style={{
                      fontSize: '0.75rem',
                      fontWeight: 700,
                      color: 'var(--success)',
                      background: 'var(--success-light)',
                      padding: '2px 8px',
                      borderRadius: '12px',
                    }}>
                      ✓ Recorded
                    </span>
                  </div>
                  <div style={{
                    background: 'var(--gray-50)',
                    border: '1px solid var(--gray-200)',
                    borderRadius: '8px',
                    padding: '0.75rem 1rem',
                    fontSize: '0.9rem',
                    color: 'var(--gray-800)',
                    lineHeight: '1.5',
                    whiteSpace: 'pre-wrap',
                  }}>
                    {attempt.feedback}
                  </div>
                </div>
              )}

              <button
                onClick={onDone}
                className="btn btn-primary"
                style={{
                  padding: '0.875rem 2.5rem',
                  fontSize: '1rem',
                  width: '100%',
                  maxWidth: '320px',
                }}
              >
                Return to Portal
              </button>
            </div>
          )}
        </div>
      </main>
    </div>
  );
};
