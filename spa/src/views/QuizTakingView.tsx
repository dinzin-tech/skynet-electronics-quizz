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
  const [submitting, setSubmitting] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

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

  return (
    <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', background: 'var(--gray-50)' }}>
      {/* Top Examination Sticky Bar */}
      <Navbar>
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
          {/* Sync Status Badge */}
          <div className={`sync-indicator sync-${syncStatus}`}>
            <span style={{ fontSize: '0.625rem' }}>●</span>
            {syncStatus === 'synced' && 'All changes saved'}
            {syncStatus === 'saving' && 'Saving answer...'}
            {syncStatus === 'offline' && 'Offline (cached locally)'}
            {syncStatus === 'error' && 'Sync retrying...'}
          </div>

          {/* Calibrated Server Timer */}
          {attemptState?.deadline_ms && (
            <Timer
              deadlineMs={attemptState.deadline_ms}
              onExpire={() => handleSubmit('timeout')}
            />
          )}
        </div>
      </Navbar>

      {/* Main Examination Layout */}
      <div style={{
        maxWidth: '1200px',
        width: '100%',
        margin: '1.5rem auto',
        padding: '0 1.5rem',
        display: 'grid',
        gridTemplateColumns: '1fr 320px',
        gap: '1.5rem',
        flex: 1,
      }}>
        {/* Left Column: Active Question */}
        <div>
          <div className="glass-card" style={{ padding: '2rem', marginBottom: '1.5rem' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
              <span style={{ fontSize: '0.875rem', fontWeight: 700, color: 'var(--primary)', textTransform: 'uppercase' }}>
                Question {currentIndex + 1} of {totalQuestions}
              </span>
              <span style={{ fontSize: '0.8125rem', color: 'var(--gray-500)' }}>
                Single choice
              </span>
            </div>

            <h3 style={{ fontSize: '1.25rem', fontWeight: 700, color: 'var(--gray-900)', marginBottom: '1.75rem', lineHeight: 1.5 }}>
              {currentQuestion?.text}
            </h3>

            {/* Answer Options */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
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
                      fontWeight: 700,
                      marginRight: '0.75rem',
                      color: isSelected ? 'var(--primary)' : 'var(--gray-500)',
                      fontSize: '0.875rem'
                    }}>
                      {letter}.
                    </span>
                    <span style={{ fontSize: '0.9375rem', color: 'var(--gray-800)', fontWeight: isSelected ? 600 : 400 }}>
                      {option.text}
                    </span>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Navigation Controls */}
          <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            background: '#ffffff',
            padding: '1rem 1.5rem',
            borderRadius: '12px',
            border: '1px solid var(--gray-200)',
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
                  className="btn btn-primary"
                >
                  Next Question →
                </button>
              ) : (
                <button
                  onClick={() => setShowReviewModal(true)}
                  disabled={submitting}
                  className="btn btn-success"
                >
                  Finish & Submit
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Right Column: Question Palette Matrix */}
        <div>
          <div className="glass-card" style={{ padding: '1.5rem' }}>
            <h4 style={{ fontSize: '1rem', fontWeight: 700, color: 'var(--gray-900)', marginBottom: '1rem' }}>
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
                className="btn btn-success"
                style={{ width: '100%', padding: '0.75rem' }}
              >
                Review & Submit Assessment
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Review & Confirmation Modal */}
      {showReviewModal && (
        <div style={{
          position: 'fixed',
          inset: 0,
          background: 'rgba(15, 23, 42, 0.6)',
          backdropFilter: 'blur(4px)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '1.5rem',
          zIndex: 50,
        }}>
          <div className="glass-card" style={{ maxWidth: '480px', width: '100%', padding: '2rem' }}>
            <h3 style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--gray-900)', marginBottom: '0.75rem' }}>
              Confirm Assessment Submission
            </h3>
            <p style={{ fontSize: '0.9375rem', color: 'var(--gray-600)', marginBottom: '1.25rem' }}>
              Are you sure you want to finish and submit your answers? Once submitted, your attempt will be permanently locked.
            </p>

            <div style={{
              background: 'var(--gray-100)',
              borderRadius: '10px',
              padding: '1rem',
              marginBottom: '1.5rem',
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
                padding: '0.75rem',
                background: 'var(--warning-light)',
                color: '#b45309',
                borderRadius: '8px',
                fontSize: '0.8125rem',
                fontWeight: 600,
                marginBottom: '1.5rem',
              }}>
                Warning: You have {unansweredCount} unanswered question(s). Unanswered questions may receive penalty points depending on the scoring policy.
              </div>
            )}

            <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'flex-end' }}>
              <button
                onClick={() => setShowReviewModal(false)}
                disabled={submitting}
                className="btn btn-secondary"
              >
                Return to Assessment
              </button>
              <button
                onClick={() => handleSubmit('manual')}
                disabled={submitting}
                className="btn btn-success"
              >
                {submitting ? 'Submitting...' : 'Yes, Submit Answers'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
