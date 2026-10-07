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
  const [feedbackInput, setFeedbackInput] = useState('');
  const [submittingFeedback, setSubmittingFeedback] = useState(false);
  const [feedbackError, setFeedbackError] = useState<string | null>(null);
  const [feedbackSavedMessage, setFeedbackSavedMessage] = useState<string | null>(null);

  const fetchAttempt = async () => {
    try {
      const data = await api.getAttempt(attemptId);
      setAttempt(data);
      if (data.feedback) {
        setFeedbackInput(data.feedback);
      }
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

  const handleSaveFeedback = async () => {
    const trimmed = feedbackInput.trim();
    if (!trimmed) {
      setFeedbackError('Feedback is compulsory. Please enter your feedback.');
      return;
    }

    setSubmittingFeedback(true);
    setFeedbackError(null);

    try {
      await api.submitFeedback(attemptId, trimmed);
      setAttempt((prev) => (prev ? { ...prev, feedback: trimmed } : null));
      setFeedbackSavedMessage('Thank you! Your feedback has been recorded.');
    } catch (err: any) {
      setFeedbackError(err?.message || 'Failed to submit feedback. Please try again.');
    } finally {
      setSubmittingFeedback(false);
    }
  };

  const handleReturnToPortal = () => {
    const hasRecordedFeedback = !!attempt?.feedback || !!feedbackSavedMessage;
    if (!hasRecordedFeedback) {
      setFeedbackError('Feedback is compulsory. Please submit your feedback before leaving.');
      return;
    }
    onDone();
  };

  const result = attempt?.result;
  const hasRecordedFeedback = !!attempt?.feedback;

  return (
    <div style={{ minHeight: '100dvh', background: 'var(--gray-50)', display: 'flex', flexDirection: 'column' }}>
      <Navbar onLogout={handleReturnToPortal} />

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

              {/* Compulsory Participant Feedback Section */}
              <div style={{
                background: '#ffffff',
                border: hasRecordedFeedback ? '1px solid var(--gray-200)' : '2px solid var(--primary)',
                borderRadius: '14px',
                padding: '1.5rem',
                textAlign: 'left',
                marginBottom: '2rem',
                boxShadow: 'var(--shadow-sm)',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.75rem' }}>
                  <h4 style={{ fontSize: '1rem', fontWeight: 700, color: 'var(--gray-900)', margin: 0, display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <span>💬</span> Participant Feedback {!hasRecordedFeedback && <span style={{ color: 'var(--danger)' }}>*</span>}
                  </h4>
                  {hasRecordedFeedback && (
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
                  )}
                </div>

                {hasRecordedFeedback ? (
                  <div>
                    <p style={{ fontSize: '0.8125rem', color: 'var(--gray-500)', marginBottom: '0.5rem' }}>
                      Your feedback has been saved and attached to this submission:
                    </p>
                    <div style={{
                      background: 'var(--gray-50)',
                      border: '1px solid var(--gray-200)',
                      borderRadius: '8px',
                      padding: '0.875rem 1rem',
                      fontSize: '0.9375rem',
                      color: 'var(--gray-800)',
                      lineHeight: '1.5',
                      whiteSpace: 'pre-wrap',
                    }}>
                      {attempt?.feedback}
                    </div>
                  </div>
                ) : (
                  <div>
                    <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginBottom: '0.75rem' }}>
                      Please provide your feedback regarding the quiz questions, timing, or platform experience. <strong>Feedback is compulsory.</strong>
                    </p>
                    <textarea
                      id="result-feedback-textarea"
                      value={feedbackInput}
                      onChange={(e) => {
                        setFeedbackInput(e.target.value);
                        if (feedbackError && e.target.value.trim()) {
                          setFeedbackError(null);
                        }
                      }}
                      rows={3}
                      placeholder="Enter your compulsory feedback here..."
                      style={{
                        width: '100%',
                        padding: '0.75rem',
                        borderRadius: '10px',
                        border: feedbackError ? '1.5px solid var(--danger)' : '1px solid var(--gray-300)',
                        fontFamily: 'inherit',
                        fontSize: '0.875rem',
                        lineHeight: '1.4',
                        resize: 'vertical',
                        boxSizing: 'border-box',
                        outline: 'none',
                      }}
                    />
                    {feedbackError && (
                      <div style={{ color: 'var(--danger)', fontSize: '0.8125rem', fontWeight: 600, marginTop: '0.375rem' }}>
                        {feedbackError}
                      </div>
                    )}
                    {feedbackSavedMessage && (
                      <div style={{ color: 'var(--success)', fontSize: '0.8125rem', fontWeight: 600, marginTop: '0.375rem' }}>
                        {feedbackSavedMessage}
                      </div>
                    )}
                    <button
                      onClick={handleSaveFeedback}
                      disabled={submittingFeedback || !feedbackInput.trim()}
                      className="btn btn-primary"
                      style={{ marginTop: '0.75rem', padding: '0.625rem 1.25rem', fontSize: '0.875rem' }}
                    >
                      {submittingFeedback ? 'Submitting...' : 'Submit Compulsory Feedback'}
                    </button>
                  </div>
                )}
              </div>

              <button
                onClick={handleReturnToPortal}
                className="btn btn-primary"
                style={{
                  padding: '0.875rem 2.5rem',
                  fontSize: '1rem',
                  width: '100%',
                  maxWidth: '320px',
                  opacity: !hasRecordedFeedback ? 0.7 : 1,
                }}
                title={!hasRecordedFeedback ? 'Please submit compulsory feedback first' : undefined}
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
