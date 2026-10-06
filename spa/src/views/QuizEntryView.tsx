import React, { useEffect, useState } from 'react';
import { api, getServerTime } from '../services/api';
import { QuizStateResponse, StartAttemptResponse, QuizListItem } from '../types';
import { Navbar } from '../components/Navbar';

interface QuizEntryViewProps {
  quizCode: string;
  onSelectQuizCode?: (code: string) => void;
  onStartAttempt: (attempt: StartAttemptResponse) => void;
  onResumeAttempt: (attemptId: string) => void;
}

export const QuizEntryView: React.FC<QuizEntryViewProps> = ({
  quizCode,
  onSelectQuizCode,
  onStartAttempt,
  onResumeAttempt,
}) => {
  const [data, setData] = useState<QuizStateResponse | null>(null);
  const [quizList, setQuizList] = useState<QuizListItem[]>([]);
  const [activeTab, setActiveTab] = useState<'active' | 'upcoming' | 'previous'>('active');
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [timeUntilOpenMs, setTimeUntilOpenMs] = useState<number | null>(null);

  const fetchQuizList = async () => {
    try {
      const res = await api.getQuizList();
      if (res && res.quizzes) {
        setQuizList(res.quizzes);
      }
    } catch {
      // Non-blocking for primary quiz view
    }
  };

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
    fetchQuizList();
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

  const activeQuizzes = quizList.filter((q) => q.category === 'active');
  const upcomingQuizzes = quizList.filter((q) => q.category === 'upcoming');
  const previousQuizzes = quizList.filter((q) => q.category === 'previous' || q.category === 'completed');

  const displayedQuizzes = 
    activeTab === 'active' ? (activeQuizzes.length > 0 ? activeQuizzes : quizList.filter(q => q.category !== 'upcoming')) :
    activeTab === 'upcoming' ? upcomingQuizzes : previousQuizzes;

  const formatDate = (ms: number) => {
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
            Loading assessment portal...
          </div>
        </div>
      </div>
    );
  }

  const meta = data?.meta;

  return (
    <div style={{ minHeight: '100dvh', background: 'var(--gray-50)', paddingBottom: '3rem' }}>
      <Navbar />

      <main style={{ maxWidth: '960px', margin: '2rem auto', padding: '0 1.25rem' }}>
        {/* PEAK PURSUIT 4.0 Dashboard Quiz Category Tabs & List */}
        <div className="glass-card" style={{ padding: '1.5rem', marginBottom: '2rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1.25rem', flexWrap: 'wrap', gap: '0.75rem' }}>
            <h3 style={{ fontSize: '1.125rem', fontWeight: 800, color: 'var(--gray-900)' }}>
              Assigned Assessments
            </h3>
            <div style={{ fontSize: '0.8125rem', color: 'var(--gold-primary)', fontWeight: 700 }}>
              PEAK PURSUIT 4.0 PORTAL
            </div>
          </div>

          {/* Tab Selection Bar */}
          <div style={{
            display: 'flex',
            gap: '0.5rem',
            marginBottom: '1.25rem',
            borderBottom: '1.5px solid var(--gray-200)',
            paddingBottom: '0.75rem',
            overflowX: 'auto',
          }}>
            <button
              onClick={() => setActiveTab('active')}
              className={`btn ${activeTab === 'active' ? 'btn-primary' : 'btn-secondary'}`}
              style={{ padding: '0.45rem 1rem', fontSize: '0.875rem', borderRadius: '20px' }}
            >
              ⚡ Active Exams ({activeQuizzes.length})
            </button>
            <button
              onClick={() => setActiveTab('upcoming')}
              className={`btn ${activeTab === 'upcoming' ? 'btn-primary' : 'btn-secondary'}`}
              style={{ padding: '0.45rem 1rem', fontSize: '0.875rem', borderRadius: '20px' }}
            >
              ⏳ Upcoming Exams ({upcomingQuizzes.length})
            </button>
            <button
              onClick={() => setActiveTab('previous')}
              className={`btn ${activeTab === 'previous' ? 'btn-primary' : 'btn-secondary'}`}
              style={{ padding: '0.45rem 1rem', fontSize: '0.875rem', borderRadius: '20px' }}
            >
              📜 Previous & Completed ({previousQuizzes.length})
            </button>
          </div>

          {/* Quiz Cards Grid */}
          {displayedQuizzes.length === 0 ? (
            <div style={{ padding: '2rem', textAlign: 'center', color: 'var(--gray-500)', fontSize: '0.9375rem' }}>
              No {activeTab} assessments found for your profile.
            </div>
          ) : (
            <div style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
              gap: '1rem',
            }}>
              {displayedQuizzes.map((q) => {
                const isSelected = q.code === quizCode;

                return (
                  <div
                    key={q.id}
                    onClick={() => onSelectQuizCode && onSelectQuizCode(q.code)}
                    style={{
                      padding: '1.125rem',
                      borderRadius: '14px',
                      border: isSelected ? '2px solid var(--gold-primary)' : '1.5px solid var(--gray-200)',
                      background: isSelected ? '#fffdf5' : '#ffffff',
                      boxShadow: isSelected ? '0 4px 14px rgba(212, 175, 55, 0.2)' : 'var(--shadow-sm)',
                      cursor: 'pointer',
                      transition: 'all 0.2s ease',
                      display: 'flex',
                      flexDirection: 'column',
                      justifyContent: 'space-between',
                    }}
                  >
                    <div>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.5rem' }}>
                        <span style={{
                          background: 'linear-gradient(135deg, #1f0508 0%, #3a080d 100%)',
                          color: 'var(--gold-primary)',
                          fontSize: '0.75rem',
                          fontWeight: 800,
                          padding: '0.2rem 0.5rem',
                          borderRadius: '6px',
                        }}>
                          {q.code}
                        </span>
                        
                        {q.category === 'active' && (
                          <span className="sync-indicator sync-synced" style={{ fontSize: '0.75rem' }}>Active Now</span>
                        )}
                        {q.category === 'upcoming' && (
                          <span className="sync-indicator sync-saving" style={{ fontSize: '0.75rem' }}>Opens {formatDate(q.opens_at_ms)}</span>
                        )}
                        {(q.category === 'previous' || q.category === 'completed') && (
                          <span className="sync-indicator sync-offline" style={{ fontSize: '0.75rem' }}>
                            {q.attempt_status === 'COMPLETED' ? 'Finished' : `Closed ${formatDate(q.closes_at_ms)}`}
                          </span>
                        )}
                      </div>

                      <h4 style={{ fontSize: '1rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '0.375rem', lineHeight: 1.3 }}>
                        {q.title}
                      </h4>
                      {q.description && (
                        <p style={{ fontSize: '0.8125rem', color: 'var(--gray-600)', marginBottom: '0.875rem', lineClamp: 2, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                          {q.description}
                        </p>
                      )}
                    </div>

                    <div style={{ paddingTop: '0.75rem', borderTop: '1px solid var(--gray-100)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <span style={{ fontSize: '0.75rem', color: 'var(--gray-500)', fontWeight: 600 }}>
                        ⏱ {Math.round(q.duration_seconds / 60)} mins
                      </span>
                      <span style={{ fontSize: '0.8125rem', fontWeight: 700, color: isSelected ? 'var(--primary)' : 'var(--gold-primary)' }}>
                        {isSelected ? 'Selected ✓' : 'View Details →'}
                      </span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

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
            {data?.state === 'not_open' && (
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
            )}

            {data?.state === 'closed' && (
              <div style={{ padding: '1.5rem', background: 'var(--gray-100)', borderRadius: '14px' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--gray-700)' }}>
                  Assessment Window Closed
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-500)', marginTop: '0.375rem' }}>
                  The examination window for this assessment has expired.
                </p>
              </div>
            )}

            {data?.state === 'ineligible' && (
              <div style={{ padding: '1.5rem', background: 'var(--danger-light)', borderRadius: '14px' }}>
                <div style={{ fontSize: '1.125rem', fontWeight: 700, color: 'var(--danger)' }}>
                  Enrollment Required
                </div>
                <p style={{ fontSize: '0.875rem', color: 'var(--gray-600)', marginTop: '0.375rem' }}>
                  Your employee profile is not enrolled in this assessment group.
                </p>
              </div>
            )}

            {data?.state === 'completed' && (
              <div style={{ padding: '1.5rem', background: 'var(--success-light)', borderRadius: '14px', border: '1px solid rgba(16, 185, 129, 0.3)' }}>
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
                  style={{ padding: '1rem 2.5rem', fontSize: '1.125rem', width: '100%', maxWidth: '360px' }}
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
                  style={{ padding: '1rem 2.5rem', fontSize: '1.125rem', width: '100%', maxWidth: '400px' }}
                >
                  {starting ? 'Starting Timer...' : 'I Am Ready — Start Assessment'}
                </button>
                <div style={{ fontSize: '0.75rem', color: 'var(--gray-500)', marginTop: '0.75rem', fontWeight: 500 }}>
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


