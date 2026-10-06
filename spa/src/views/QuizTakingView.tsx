import React, { useEffect, useState, useMemo, useCallback } from 'react';
import { api } from '../services/api';
import { syncManager, SyncStatus } from '../services/syncManager';
import {
  AttemptStateResponse,
  QuizBundle,
  Question,
  AnswerOption,
} from '../types';
import { Navbar } from '../components/Navbar';
import { Timer } from '../components/Timer';

interface QuizTakingViewProps {
  attemptId: string;
  onSubmitted: (attemptId: string) => void;
}

export const QuizTakingView: React.FC<QuizTakingViewProps> = ({ attemptId, onSubmitted }) => {
  const [bundle, setBundle] = useState<QuizBundle | null>(null);
  const [attemptState, setAttemptState] = useState<AttemptStateResponse | null>(null);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [answers, setAnswers] = useState<Record<string, number>>({});
  const [syncStatus, setSyncStatus] = useState<SyncStatus>('synced');
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [showMobileDrawer, setShowMobileDrawer] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [zoomImage, setZoomImage] = useState<string | null>(null);

  // 1. Initial Load: Bundle + Attempt State
  useEffect(() => {
    let mounted = true;

    async function init() {
      try {
        setLoading(true);
        const [bundleData, stateData] = await Promise.all([
          api.fetchBundle(attemptId),
          api.getAttempt(attemptId),
        ]);

        if (!mounted) return;

        if (stateData.status === 'COMPLETED') {
          onSubmitted(attemptId);
          return;
        }

        setBundle(bundleData);
        setAttemptState(stateData);

        // Pre-populate saved answers
        const initialAnswers: Record<string, number> = {};
        for (const [qid, ans] of Object.entries(stateData.answers || {})) {
          initialAnswers[qid] = ans.selected_option_id;
        }
        setAnswers(initialAnswers);

        // Initialize sync manager
        await syncManager.init(attemptId, stateData.max_seq || 0);
      } catch (err: any) {
        if (!mounted) return;
        setError(err?.message || 'Failed to initialize examination session');
      } finally {
        if (mounted) setLoading(false);
      }
    }

    init();

    const unsubSync = syncManager.onStatusChange((status) => {
      if (mounted) setSyncStatus(status);
    });

    return () => {
      mounted = false;
      unsubSync();
      syncManager.destroy();
    };
  }, [attemptId, onSubmitted]);

  // 2. Re-order questions and options according to server layout
  const orderedQuestions = useMemo<Question[]>(() => {
    if (!bundle || !attemptState?.layout) {
      return bundle?.questions || [];
    }

    const questionsMap = new Map<number, Question>();
    bundle.questions.forEach((q) => questionsMap.set(q.id, q));

    const layout = attemptState.layout;
    const result: Question[] = [];

    for (const qid of layout.q) {
      const q = questionsMap.get(qid);
      if (!q) continue;

      const optionOrder = layout.o[qid.toString()] || [];
      const optionsMap = new Map<number, AnswerOption>();
      q.options.forEach((opt) => optionsMap.set(opt.id, opt));

      const reorderedOptions: AnswerOption[] = [];
      for (const oid of optionOrder) {
        const opt = optionsMap.get(oid);
        if (opt) reorderedOptions.push(opt);
      }

      result.push({
        ...q,
        options: reorderedOptions.length > 0 ? reorderedOptions : q.options,
      });
    }

    return result.length > 0 ? result : bundle.questions;
  }, [bundle, attemptState]);

  const currentQuestion = orderedQuestions[currentIndex];
  const totalQuestions = orderedQuestions.length;
  const answeredCount = Object.keys(answers).length;
  const unansweredCount = totalQuestions - answeredCount;

  const navSettings = bundle?.settings?.navigation || {};
  const allowBack = navSettings.allow_back ?? true;
  const allowSkip = navSettings.allow_skip ?? true;

  // 3. Select Answer Option
  const handleSelectOption = useCallback(
    async (optionId: number) => {
      if (!currentQuestion || submitting) return;

      const qid = currentQuestion.id;
      // Optimistic state update
      setAnswers((prev) => ({ ...prev, [qid.toString()]: optionId }));

      // Queue answer in sync manager
      await syncManager.queueAnswer(qid, optionId);
    },
    [currentQuestion, submitting]
  );

  // 4. Submit Attempt
  const handleSubmit = async (reason: 'manual' | 'timeout' = 'manual') => {
    if (submitting) return;
    setSubmitting(true);
    setShowReviewModal(false);

    try {
      // Flush any pending unacked answers
      await syncManager.flush();
      await api.submitAttempt(attemptId, reason);
      onSubmitted(attemptId);
    } catch (err: any) {
      setError(err?.message || 'Submission failed. Please retry.');
      setSubmitting(false);
    }
  };

  // 5. Keyboard Navigation (1-4, A-D)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (showReviewModal || submitting || !currentQuestion) return;

      const key = e.key.toUpperCase();
      let optIndex = -1;

      if (['1', '2', '3', '4'].includes(key)) {
        optIndex = parseInt(key, 10) - 1;
      } else if (['A', 'B', 'C', 'D'].includes(key)) {
        optIndex = key.charCodeAt(0) - 65; // A=0, B=1, C=2, D=3
      }

      if (optIndex >= 0 && optIndex < currentQuestion.options.length) {
        handleSelectOption(currentQuestion.options[optIndex].id);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [currentQuestion, showReviewModal, submitting, handleSelectOption]);

  if (loading) {
    return (
      <div>
        <Navbar />
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '60vh' }}>
          <div style={{ fontSize: '1.125rem', color: 'var(--gray-500)', fontWeight: 500 }}>
            Loading questions and session state...
          </div>
        </div>
      </div>
    );
  }

  if (error && !currentQuestion) {
    return (
      <div>
        <Navbar />
        <div style={{ maxWidth: '600px', margin: '4rem auto', padding: '2rem', textAlign: 'center' }}>
          <div style={{ color: 'var(--danger)', fontSize: '1.25rem', fontWeight: 700, marginBottom: '1rem' }}>
            {error}
          </div>
          <button onClick={() => window.location.reload()} className="btn btn-primary">
            Retry Loading Session
          </button>
        </div>
      </div>
    );
  }

  const selectedOptionId = currentQuestion ? answers[currentQuestion.id.toString()] : undefined;
  const questionImage = currentQuestion?.image_url || currentQuestion?.image_path;

  return (
    <div style={{ minHeight: '100dvh', display: 'flex', flexDirection: 'column', background: 'var(--gray-50)' }}>
      {/* Top Examination Sticky Bar */}
      <Navbar>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          {/* Sync Status Badge */}
          <div className={`sync-indicator sync-${syncStatus}`}>
            <span style={{ fontSize: '0.625rem' }}>●</span>
            {syncStatus === 'synced' && 'Saved'}
            {syncStatus === 'saving' && 'Saving...'}
            {syncStatus === 'offline' && 'Offline'}
            {syncStatus === 'error' && 'Sync error'}
          </div>

          {/* Calibrated Server Timer */}
          {attemptState?.deadline_ms && (
            <Timer
              deadlineMs={attemptState.deadline_ms}
              onExpire={() => handleSubmit('timeout')}
            />
          )}

          {/* Mobile Question Palette Toggle */}
          <button
            onClick={() => setShowMobileDrawer(true)}
            className="btn btn-secondary"
            style={{ padding: '0.4rem 0.65rem', fontSize: '0.8125rem' }}
          >
            📋 {answeredCount}/{totalQuestions}
          </button>
        </div>
      </Navbar>

      {/* Main Examination Layout */}
      <div className="quiz-layout-grid" style={{
        maxWidth: '1240px',
        width: '100%',
        margin: '1.25rem auto 5rem auto',
        padding: '0 1.25rem',
        display: 'grid',
        gridTemplateColumns: '1fr 320px',
        gap: '1.5rem',
        flex: 1,
      }}>
        {/* Left Column: Active Question */}
        <div>
          <div className="glass-card" style={{ padding: '2rem 1.75rem', marginBottom: '1.25rem' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <span style={{ 
                fontSize: '0.875rem', 
                fontWeight: 800, 
                color: 'var(--primary)', 
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
              }}>
                Question {currentIndex + 1} of {totalQuestions}
              </span>
              <span style={{ fontSize: '0.8125rem', color: 'var(--gold-primary)', fontWeight: 700 }}>
                PEAK PURSUIT 4.0
              </span>
            </div>

            <h3 style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginBottom: '1.75rem', lineHeight: 1.5 }}>
              {currentQuestion?.text}
            </h3>

            {/* Question Illustration Image */}
            {questionImage && (
              <div className="question-image-wrapper">
                <img
                  src={questionImage}
                  alt={`Question ${currentIndex + 1} illustration`}
                  className="question-image"
                  onClick={() => setZoomImage(questionImage)}
                  title="Click to zoom in"
                />
                <div className="question-image-caption">
                  <span>🔍 Click image to enlarge</span>
                </div>
              </div>
            )}

            {/* Answer Options */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.875rem' }}>
              {currentQuestion?.options.map((option, idx) => {
                const isSelected = selectedOptionId === option.id;
                const letter = String.fromCharCode(65 + idx);

                return (
                  <div
                    key={option.id}
                    onClick={() => handleSelectOption(option.id)}
                    className={`option-card ${isSelected ? 'selected' : ''}`}
                  >
                    <div className="option-radio">
                      {isSelected && <div className="option-radio-dot" />}
                    </div>
                    <span style={{
                      fontWeight: 800,
                      marginRight: '0.75rem',
                      color: isSelected ? 'var(--primary)' : 'var(--gray-500)',
                      fontSize: '0.9375rem',
                      marginTop: '2px',
                    }}>
                      {letter}.
                    </span>
                    <span style={{ fontSize: '0.9375rem', color: 'var(--gray-800)', fontWeight: isSelected ? 700 : 400, lineHeight: 1.5 }}>
                      {option.text}
                    </span>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Desktop Navigation Controls */}
          <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: '#ffffff',
            padding: '1rem 1.25rem',
            borderRadius: '14px',
            border: '1.5px solid var(--gray-200)',
            boxShadow: 'var(--shadow-sm)',
          }}>
            <div>
              {allowBack && (
                <button
                  onClick={() => setCurrentIndex((prev) => Math.max(0, prev - 1))}
                  disabled={currentIndex === 0 || submitting}
                  className="btn btn-secondary"
                >
                  ← Previous
                </button>
              )}
            </div>

            <div style={{ display: 'flex', gap: '0.75rem' }}>
              {allowSkip && currentIndex < totalQuestions - 1 && (
                <button
                  onClick={() => setCurrentIndex((prev) => Math.min(totalQuestions - 1, prev + 1))}
                  disabled={submitting}
                  className="btn btn-secondary"
                >
                  Skip
                </button>
              )}

              {currentIndex < totalQuestions - 1 ? (
                <button
                  onClick={() => setCurrentIndex((prev) => Math.min(totalQuestions - 1, prev + 1))}
                  disabled={submitting}
                  className="btn btn-crimson"
                >
                  Next Question →
                </button>
              ) : (
                <button
                  onClick={() => setShowReviewModal(true)}
                  disabled={submitting}
                  className="btn btn-primary"
                >
                  Finish & Submit
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Right Column: Question Palette Matrix (Desktop Sidebar) */}
        <div className="desktop-sidebar">
          <div className="glass-card" style={{ padding: '1.5rem', position: 'sticky', top: '90px' }}>
            <h4 style={{ fontSize: '1rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '1rem' }}>
              Question Matrix
            </h4>

            <div style={{
              display: 'flex',
              justifyContent: 'space-between',
              fontSize: '0.8125rem',
              color: 'var(--gray-600)',
              marginBottom: '1rem',
              paddingBottom: '0.75rem',
              borderBottom: '1px solid var(--gray-200)',
            }}>
              <span>Answered: <strong style={{ color: 'var(--success)' }}>{answeredCount}</strong></span>
              <span>Remaining: <strong style={{ color: 'var(--warning)' }}>{unansweredCount}</strong></span>
            </div>

            <div className="matrix-grid">
              {orderedQuestions.map((q, idx) => {
                const isAnswered = answers[q.id.toString()] !== undefined;
                const isCurrent = idx === currentIndex;

                return (
                  <button
                    key={q.id}
                    onClick={() => setCurrentIndex(idx)}
                    className={`matrix-btn ${isAnswered ? 'answered' : ''} ${isCurrent ? 'current' : ''}`}
                    title={`Question ${idx + 1}`}
                  >
                    {idx + 1}
                  </button>
                );
              })}
            </div>

            <div style={{ marginTop: '1.5rem', paddingTop: '1rem', borderTop: '1px solid var(--gray-200)' }}>
              <button
                onClick={() => setShowReviewModal(true)}
                disabled={submitting}
                className="btn btn-primary"
                style={{ width: '100%', padding: '0.75rem' }}
              >
                Review & Submit Assessment
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Mobile Fixed Bottom Navigation Bar */}
      <div className="mobile-bottom-nav">
        {allowBack && (
          <button
            onClick={() => setCurrentIndex((prev) => Math.max(0, prev - 1))}
            disabled={currentIndex === 0 || submitting}
            className="btn btn-secondary"
            style={{ padding: '0.5rem 0.875rem', fontSize: '0.875rem' }}
          >
            ← Prev
          </button>
        )}

        <button
          onClick={() => setShowMobileDrawer(true)}
          className="btn btn-secondary"
          style={{ padding: '0.5rem 0.875rem', fontSize: '0.875rem', background: 'var(--gold-light)', borderColor: 'var(--gold-primary)' }}
        >
          Grid ({answeredCount}/{totalQuestions})
        </button>

        {currentIndex < totalQuestions - 1 ? (
          <button
            onClick={() => setCurrentIndex((prev) => Math.min(totalQuestions - 1, prev + 1))}
            disabled={submitting}
            className="btn btn-crimson"
            style={{ padding: '0.5rem 1rem', fontSize: '0.875rem' }}
          >
            Next →
          </button>
        ) : (
          <button
            onClick={() => setShowReviewModal(true)}
            disabled={submitting}
            className="btn btn-primary"
            style={{ padding: '0.5rem 1rem', fontSize: '0.875rem' }}
          >
            Submit
          </button>
        )}
      </div>

      {/* Mobile Question Matrix Drawer Overlay */}
      {showMobileDrawer && (
        <div className="drawer-overlay" onClick={() => setShowMobileDrawer(false)}>
          <div className="drawer-content" onClick={(e) => e.stopPropagation()}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
              <h4 style={{ fontSize: '1.125rem', fontWeight: 800, color: 'var(--gray-900)' }}>
                Question Matrix
              </h4>
              <button
                onClick={() => setShowMobileDrawer(false)}
                className="btn btn-secondary"
                style={{ padding: '0.25rem 0.5rem', minHeight: 'auto', fontSize: '1.25rem', border: 'none' }}
              >
                ✕
              </button>
            </div>

            <div style={{
              display: 'flex',
              justifyContent: 'space-between',
              fontSize: '0.875rem',
              color: 'var(--gray-600)',
              marginBottom: '1.25rem',
              paddingBottom: '0.75rem',
              borderBottom: '1px solid var(--gray-200)',
            }}>
              <span>Answered: <strong style={{ color: 'var(--success)' }}>{answeredCount}</strong></span>
              <span>Remaining: <strong style={{ color: 'var(--warning)' }}>{unansweredCount}</strong></span>
            </div>

            <div className="matrix-grid" style={{ marginBottom: '1.5rem' }}>
              {orderedQuestions.map((q, idx) => {
                const isAnswered = answers[q.id.toString()] !== undefined;
                const isCurrent = idx === currentIndex;

                return (
                  <button
                    key={q.id}
                    onClick={() => {
                      setCurrentIndex(idx);
                      setShowMobileDrawer(false);
                    }}
                    className={`matrix-btn ${isAnswered ? 'answered' : ''} ${isCurrent ? 'current' : ''}`}
                  >
                    {idx + 1}
                  </button>
                );
              })}
            </div>

            <button
              onClick={() => {
                setShowMobileDrawer(false);
                setShowReviewModal(true);
              }}
              disabled={submitting}
              className="btn btn-primary"
              style={{ width: '100%', padding: '0.875rem', marginTop: 'auto' }}
            >
              Review & Submit
            </button>
          </div>
        </div>
      )}

      {/* Review & Confirmation Modal */}
      {showReviewModal && (
        <div style={{
          position: 'fixed',
          inset: 0,
          background: 'rgba(15, 23, 42, 0.65)',
          backdropFilter: 'blur(4px)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '1.25rem',
          zIndex: 110,
        }}>
          <div className="glass-card" style={{ maxWidth: '480px', width: '100%', padding: '2rem 1.5rem' }}>
            <h3 style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '0.75rem' }}>
              Confirm Assessment Submission
            </h3>
            <p style={{ fontSize: '0.9375rem', color: 'var(--gray-600)', marginBottom: '1.25rem' }}>
              Are you sure you want to finish and submit your answers? Once submitted, your attempt will be permanently locked.
            </p>

            <div style={{
              background: 'var(--gray-100)',
              borderRadius: '12px',
              padding: '1rem',
              marginBottom: '1.5rem',
              border: '1px solid var(--gray-200)',
            }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem', fontSize: '0.875rem' }}>
                <span style={{ color: 'var(--gray-600)' }}>Total Questions:</span>
                <strong>{totalQuestions}</strong>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem', fontSize: '0.875rem' }}>
                <span style={{ color: 'var(--gray-600)' }}>Answered:</span>
                <strong style={{ color: 'var(--success)' }}>{answeredCount}</strong>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.875rem' }}>
                <span style={{ color: 'var(--gray-600)' }}>Unanswered:</span>
                <strong style={{ color: unansweredCount > 0 ? 'var(--warning)' : 'var(--gray-600)' }}>
                  {unansweredCount}
                </strong>
              </div>
            </div>

            {unansweredCount > 0 && (
              <div style={{
                padding: '0.75rem 1rem',
                background: 'var(--warning-light)',
                color: '#b45309',
                borderRadius: '10px',
                fontSize: '0.8125rem',
                fontWeight: 600,
                marginBottom: '1.5rem',
                border: '1px solid rgba(245, 158, 11, 0.3)',
              }}>
                Warning: You have {unansweredCount} unanswered question(s). Unanswered questions may receive penalty points depending on scoring policies.
              </div>
            )}

            <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'flex-end', flexWrap: 'wrap' }}>
              <button
                onClick={() => setShowReviewModal(false)}
                disabled={submitting}
                className="btn btn-secondary"
                style={{ flex: 1 }}
              >
                Back
              </button>
              <button
                onClick={() => handleSubmit('manual')}
                disabled={submitting}
                className="btn btn-primary"
                style={{ flex: 1 }}
              >
                {submitting ? 'Submitting...' : 'Yes, Submit Answers'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Question Image Lightbox Modal */}
      {zoomImage && (
        <div 
          onClick={() => setZoomImage(null)}
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(15, 23, 42, 0.85)',
            backdropFilter: 'blur(8px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '1.5rem',
            zIndex: 120,
            cursor: 'zoom-out',
          }}
        >
          <div 
            style={{ position: 'relative', maxWidth: '92vw', maxHeight: '90vh' }} 
            onClick={(e) => e.stopPropagation()}
          >
            <button
              onClick={() => setZoomImage(null)}
              style={{
                position: 'absolute',
                top: '-16px',
                right: '-16px',
                width: '38px',
                height: '38px',
                borderRadius: '50%',
                background: '#ffffff',
                border: '2px solid var(--gray-300)',
                color: 'var(--gray-800)',
                fontSize: '1.25rem',
                fontWeight: 'bold',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                boxShadow: 'var(--shadow-md)',
                zIndex: 121,
              }}
              title="Close preview"
            >
              ✕
            </button>
            <img
              src={zoomImage}
              alt="Full size illustration"
              style={{
                maxWidth: '92vw',
                maxHeight: '85vh',
                objectFit: 'contain',
                borderRadius: '12px',
                boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.5)',
                background: '#ffffff',
                display: 'block',
              }}
            />
          </div>
        </div>
      )}
    </div>
  );
};

