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
    <div style={{ minHeight: '100vh', background: 'var(--gray-50)', display: 'flex', flexDirection: 'column' }}>
      <Navbar onLogout={onDone} />

      <main style={{ maxWidth: '640px', width: '100%', margin: '3rem auto', padding: '0 1.5rem', flex: 1 }}>
        <div className="glass-card" style={{ padding: '2.5rem', textAlign: 'center' }}>
          <div style={{
            width: '64px',
            height: '64px',
            borderRadius: '50%',
            background: 'var(--success-light)',
            color: 'var(--success)',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: '2rem',
            marginBottom: '1rem',
          }}>
            ✓
          </div>

          <h2 style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '0.5rem' }}>
            Assessment Completed
          </h2>
          <p style={{ fontSize: '0.9375rem', color: 'var(--gray-600)', marginBottom: '2rem' }}>
            Your assessment has been securely submitted and permanently recorded.
          </p>

          {loading || !result ? (
            <div style={{ padding: '2rem 1rem' }}>
              <div style={{ fontSize: '1rem', fontWeight: 600, color: 'var(--primary)', marginBottom: '0.5rem' }}>
                Finalizing score calculations...
              </div>
              <div style={{ fontSize: '0.8125rem', color: 'var(--gray-500)' }}>
                Please wait a moment while the grading engine computes your results.
              </div>
            </div>
          ) : (
            <div>
              <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(2, 1fr)',
                gap: '1rem',
                marginBottom: '2rem',
              }}>
                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '12px',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--gray-500)', textTransform: 'uppercase' }}>
                    Final Score
                  </div>
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--primary)', marginTop: '0.25rem' }}>
                    {result.score} pts
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '12px',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--gray-500)', textTransform: 'uppercase' }}>
                    Accuracy
                  </div>
                  <div style={{ fontSize: '1.75rem', fontWeight: 800, color: 'var(--success)', marginTop: '0.25rem' }}>
                    {result.accuracy}%
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '12px',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--gray-500)', textTransform: 'uppercase' }}>
                    Correct Answers
                  </div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                    {result.correct_count} / {result.total_questions}
                  </div>
                </div>

                <div style={{
                  padding: '1.25rem',
                  background: 'var(--gray-100)',
                  borderRadius: '12px',
                  textAlign: 'left',
                }}>
                  <div style={{ fontSize: '0.75rem', fontWeight: 600, color: 'var(--gray-500)', textTransform: 'uppercase' }}>
                    Completion Time
                  </div>
                  <div style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginTop: '0.25rem' }}>
                    {Math.floor(result.completion_time_s / 60)}m {result.completion_time_s % 60}s
                  </div>
                </div>
              </div>

              <button
                onClick={onDone}
                className="btn btn-primary"
                style={{ padding: '0.875rem 2rem', fontSize: '1rem' }}
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
