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
  const currentWeekLabel = attempt?.week_label || (attempt?.week_number ? `WEEK - ${attempt.week_number}` : undefined);
  const quizTitle = attempt?.quiz_title;

  return (
    <div className="peak-app-bg" style={{ minHeight: '100dvh', display: 'flex', flexDirection: 'column' }}>
      <Navbar onLogout={onDone} statusBadge="COMPLETED" currentWeekLabel={currentWeekLabel} />

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
          <p style={{ fontSize: '1rem', color: 'rgba(255, 255, 255, 0.85)', marginBottom: '2rem' }}>
            Thank you for submitting your responses for {quizTitle ? (
              <strong style={{ color: 'var(--peak-gold-main)' }}>{quizTitle}</strong>
            ) : (
              'this assessment'
            )}.
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
                  marginBottom: '1.5rem',
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

              {/* Grievance Helpline & Support Card */}
              {attempt?.helpline && attempt.helpline.enabled !== false && (
                attempt.helpline.phone || attempt.helpline.email || attempt.helpline.text || attempt.helpline.image_path
              ) && (
                <div style={{
                  background: 'linear-gradient(135deg, rgba(30, 41, 59, 0.7) 0%, rgba(15, 23, 42, 0.9) 100%)',
                  border: '1.5px solid rgba(245, 197, 66, 0.35)',
                  borderRadius: '16px',
                  padding: '1.75rem',
                  textAlign: 'left',
                  marginBottom: '2rem',
                  boxShadow: '0 8px 32px rgba(0, 0, 0, 0.4)',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem', borderBottom: '1px solid rgba(255, 255, 255, 0.1)', paddingBottom: '0.75rem' }}>
                    <span style={{ fontSize: '1.5rem' }}>🛡️</span>
                    <div>
                      <h3 style={{ fontSize: '1.1rem', fontWeight: 800, color: '#ffffff', margin: 0 }}>
                        Grievance Helpline & Employee Support
                      </h3>
                      <div style={{ fontSize: '0.8rem', color: 'rgba(255, 255, 255, 0.65)' }}>
                        Reach out for any ethics concerns, queries, or assistance regarding this assessment
                      </div>
                    </div>
                  </div>

                  {/* Poster Image (if uploaded) */}
                  {attempt.helpline.image_path && (
                    <div style={{ marginBottom: '1.25rem', textAlign: 'center' }}>
                      <img
                        src={attempt.helpline.image_path}
                        alt="Grievance Helpline Poster"
                        style={{
                          maxWidth: '100%',
                          maxHeight: '360px',
                          borderRadius: '12px',
                          border: '1px solid rgba(255, 255, 255, 0.15)',
                          boxShadow: '0 4px 20px rgba(0, 0, 0, 0.5)',
                          objectFit: 'contain',
                        }}
                      />
                    </div>
                  )}

                  {/* Notice text */}
                  {attempt.helpline.text && (
                    <div style={{
                      fontSize: '0.925rem',
                      color: 'rgba(255, 255, 255, 0.9)',
                      lineHeight: '1.6',
                      marginBottom: '1.25rem',
                      background: 'rgba(255, 255, 255, 0.04)',
                      padding: '1rem',
                      borderRadius: '10px',
                      borderLeft: '3px solid var(--peak-gold-main)',
                      whiteSpace: 'pre-wrap',
                    }}>
                      {attempt.helpline.text}
                    </div>
                  )}

                  {/* Contact Methods */}
                  {(attempt.helpline.phone || attempt.helpline.email) && (
                    <div style={{
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                      gap: '1rem',
                    }}>
                      {attempt.helpline.phone && (
                        <a
                          href={`tel:${attempt.helpline.phone.replace(/[^0-9+]/g, '')}`}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '0.75rem',
                            padding: '0.875rem 1rem',
                            background: 'rgba(255, 255, 255, 0.06)',
                            border: '1px solid rgba(255, 255, 255, 0.12)',
                            borderRadius: '10px',
                            color: '#ffffff',
                            textDecoration: 'none',
                          }}
                        >
                          <span style={{ fontSize: '1.25rem' }}>📞</span>
                          <div>
                            <div style={{ fontSize: '0.725rem', color: 'rgba(255, 255, 255, 0.5)', textTransform: 'uppercase', fontWeight: 700 }}>
                              Helpline Phone
                            </div>
                            <div style={{ fontSize: '0.95rem', fontWeight: 700, color: 'var(--peak-gold-main)' }}>
                              {attempt.helpline.phone}
                            </div>
                          </div>
                        </a>
                      )}

                      {attempt.helpline.email && (
                        <a
                          href={`mailto:${attempt.helpline.email}`}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '0.75rem',
                            padding: '0.875rem 1rem',
                            background: 'rgba(255, 255, 255, 0.06)',
                            border: '1px solid rgba(255, 255, 255, 0.12)',
                            borderRadius: '10px',
                            color: '#ffffff',
                            textDecoration: 'none',
                          }}
                        >
                          <span style={{ fontSize: '1.25rem' }}>✉️</span>
                          <div>
                            <div style={{ fontSize: '0.725rem', color: 'rgba(255, 255, 255, 0.5)', textTransform: 'uppercase', fontWeight: 700 }}>
                              Helpline Email
                            </div>
                            <div style={{ fontSize: '0.95rem', fontWeight: 700, color: '#38bdf8' }}>
                              {attempt.helpline.email}
                            </div>
                          </div>
                        </a>
                      )}
                    </div>
                  )}
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
