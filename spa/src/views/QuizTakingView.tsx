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
import { DualTimer } from '../components/DualTimer';

interface QuizTakingViewProps {
  attemptId: string;
  onSubmitted: (attemptId: string) => void;
}

export const QuizTakingView: React.FC<QuizTakingViewProps> = ({ attemptId, onSubmitted }) => {
  const [bundle, setBundle] = useState<QuizBundle | null>(null);
  const [attemptState, setAttemptState] = useState<AttemptStateResponse | null>(null);
  const [currentIndex, setCurrentIndex] = useState(0); // 0..N-1 for Questions, N for Feedback
  const [answers, setAnswers] = useState<Record<string, number>>({});
  const [writtenAnswers, setWrittenAnswers] = useState<Record<string, string>>({});
  const [feedback, setFeedback] = useState('');
  const [syncStatus, setSyncStatus] = useState<SyncStatus>('synced');
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [showIncompleteAlert, setShowIncompleteAlert] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submittingReason, setSubmittingReason] = useState<'manual' | 'timeout' | null>(null);
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
        const initialWritten: Record<string, string> = {};
        for (const [qid, ans] of Object.entries(stateData.answers || {})) {
          if (ans.selected_option_id && ans.selected_option_id > 0) {
            initialAnswers[qid] = ans.selected_option_id;
          }
          if (ans.text_answer) {
            initialWritten[qid] = ans.text_answer;
          }
        }
        setAnswers(initialAnswers);
        setWrittenAnswers(initialWritten);

        // Pre-populate feedback if saved previously
        if (stateData.feedback) {
          setFeedback(stateData.feedback);
        }

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

  // 2. Re-order MCQs according to server layout
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

  const totalQuestions = orderedQuestions.length;
  // Feedback is the final item: index === totalQuestions
  const totalItems = totalQuestions + 1;
  const isFeedbackActive = currentIndex === totalQuestions;
  const currentQuestion = !isFeedbackActive ? orderedQuestions[currentIndex] : null;

  // Answer status calculation (handles both MCQ and Written questions)
  const questionAnsweredCount = useMemo(() => {
    let count = 0;
    for (const q of orderedQuestions) {
      if (q.type === 'written') {
        if ((writtenAnswers[q.id.toString()] || '').trim().length > 0) {
          count++;
        }
      } else {
        if (answers[q.id.toString()] !== undefined) {
          count++;
        }
      }
    }
    return count;
  }, [orderedQuestions, answers, writtenAnswers]);

  const isFeedbackAnswered = feedback.trim().length > 0;
  const totalAnswered = questionAnsweredCount + (isFeedbackAnswered ? 1 : 0);
  const remainingCount = totalItems - totalAnswered;

  // Compute list of missing items for incomplete warning
  const missingItems = useMemo(() => {
    const list: string[] = [];
    orderedQuestions.forEach((q, idx) => {
      if (q.type === 'written') {
        if ((writtenAnswers[q.id.toString()] || '').trim().length === 0) {
          list.push(`Question ${idx + 1} (Written)`);
        }
      } else {
        if (answers[q.id.toString()] === undefined) {
          list.push(`Question ${idx + 1}`);
        }
      }
    });
    if (!isFeedbackAnswered) {
      list.push('Feedback Question');
    }
    return list;
  }, [orderedQuestions, answers, writtenAnswers, isFeedbackAnswered]);

  // 3. Option Selection (for MCQ)
  const handleSelectOption = useCallback(
    async (optionId: number) => {
      if (!currentQuestion || submitting) return;

      const qid = currentQuestion.id;
      // Optimistic update
      setAnswers((prev) => ({ ...prev, [qid.toString()]: optionId }));

      // Queue answer in sync manager
      await syncManager.queueAnswer(qid, optionId);
    },
    [currentQuestion, submitting]
  );

  // 3b. Written Answer Typing
  const handleWrittenAnswerChange = useCallback(
    async (text: string) => {
      if (!currentQuestion || submitting) return;

      const qid = currentQuestion.id;
      setWrittenAnswers((prev) => ({ ...prev, [qid.toString()]: text }));

      // Queue written response in sync manager
      await syncManager.queueWrittenAnswer(qid, text);
    },
    [currentQuestion, submitting]
  );

  // 4. Save feedback in real-time or on blur
  const handleFeedbackChange = (text: string) => {
    setFeedback(text);
  };

  // 5. Final Submission
  const performSubmit = async (reason: 'manual' | 'timeout') => {
    if (submitting) return;
    setSubmitting(true);
    setSubmittingReason(reason);
    setShowReviewModal(false);
    setShowIncompleteAlert(false);

    try {
      // Flush pending answer syncs
      try {
        await syncManager.flush();
      } catch {
        // Continue submission attempt
      }

      await api.submitAttempt(attemptId, reason, feedback.trim());
      onSubmitted(attemptId);
    } catch (err: any) {
      setError(err?.message || 'Submission failed. Please try again.');
      setSubmitting(false);
      setSubmittingReason(null);
    }
  };

  // 6. Review & Submit Handler
  const handleReviewAndSubmitClick = () => {
    if (missingItems.length > 0) {
      setShowIncompleteAlert(true);
    } else {
      setShowReviewModal(true);
    }
  };

  // 7. Auto-submit on Timer Expiry
  const handleTimeoutExpire = useCallback(() => {
    performSubmit('timeout');
  }, [attemptId, feedback]);

  // 8. Keyboard navigation for options (1-4, A-D)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (showReviewModal || showIncompleteAlert || submitting || isFeedbackActive || !currentQuestion) {
        return;
      }

      // Ignore when typing in an input or textarea
      if (['INPUT', 'TEXTAREA'].includes((e.target as HTMLElement)?.tagName)) {
        return;
      }

      const key = e.key.toUpperCase();
      let optIndex = -1;

      if (['1', '2', '3', '4'].includes(key)) {
        optIndex = parseInt(key, 10) - 1;
      } else if (['A', 'B', 'C', 'D'].includes(key)) {
        optIndex = key.charCodeAt(0) - 65;
      }

      if (optIndex >= 0 && optIndex < currentQuestion.options.length) {
        handleSelectOption(currentQuestion.options[optIndex].id);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [currentQuestion, showReviewModal, showIncompleteAlert, submitting, isFeedbackActive, handleSelectOption]);

  if (loading) {
    return (
      <div className="peak-app-bg" style={{ minHeight: '100dvh' }}>
        <Navbar />
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '60vh' }}>
          <div style={{ fontSize: '1.125rem', color: 'rgba(255, 255, 255, 0.7)', fontWeight: 600 }}>
            Initializing examination environment...
          </div>
        </div>
      </div>
    );
  }

  if (error && !currentQuestion && !isFeedbackActive) {
    return (
      <div className="peak-app-bg" style={{ minHeight: '100dvh' }}>
        <Navbar />
        <div style={{ maxWidth: '600px', margin: '4rem auto', padding: '2rem', textAlign: 'center' }}>
          <div style={{ color: '#f87171', fontSize: '1.25rem', fontWeight: 700, marginBottom: '1rem' }}>
            {error}
          </div>
          <button onClick={() => window.location.reload()} className="peak-submit-btn" style={{ padding: '0.75rem 2rem' }}>
            Retry Loading Session
          </button>
        </div>
      </div>
    );
  }

  const selectedOptionId = currentQuestion ? answers[currentQuestion.id.toString()] : undefined;
  const questionImage = currentQuestion?.image_url || currentQuestion?.image_path;
  const feedbackPrompt = bundle?.feedback_question?.trim() || 'Please share your feedback and experience regarding this assessment challenge:';

  return (
    <div className="peak-app-bg" style={{ minHeight: '100dvh', display: 'flex', flexDirection: 'column' }}>
      {/* Top Brand Banner with Dual Timers & Sync Status */}
      <Navbar
        currentWeekLabel={bundle?.week_label || (bundle?.week_number ? `WEEK - ${bundle.week_number}` : undefined)}
        statusBadge="CONTEST LIVE"
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.85rem', flexWrap: 'wrap' }}>
          {/* Real-time Save Indicator */}
          <div className={`sync-indicator sync-${syncStatus}`} style={{ fontSize: '0.75rem', padding: '0.25rem 0.6rem' }}>
            <span style={{ fontSize: '0.625rem' }}>●</span>
            {syncStatus === 'synced' && 'Answers Synced'}
            {syncStatus === 'saving' && 'Saving...'}
            {syncStatus === 'offline' && 'Offline (Queued)'}
            {syncStatus === 'error' && 'Sync Retry'}
          </div>

          {/* DUAL TIMERS */}
          {attemptState?.deadline_ms && (
            <DualTimer
              deadlineMs={attemptState.deadline_ms}
              portalClosingMs={attemptState.portal_closing_ms}
              onExpire={handleTimeoutExpire}
            />
          )}
        </div>
      </Navbar>

      {/* Main Examination Workspace: Left Canvas + Right Palette */}
      <div className="exam-taking-wrapper">
        {/* Left: Active Question Canvas */}
        <main style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          {/* Question or Feedback Card */}
          <div className="glass-card-dark" style={{ padding: '2rem 1.75rem', position: 'relative' }}>
            {/* Header: Item Counter & Answered Badge */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.25rem' }}>
              <span style={{
                fontSize: '0.8125rem',
                fontWeight: 800,
                color: 'var(--peak-gold-main)',
                textTransform: 'uppercase',
                letterSpacing: '0.06em',
              }}>
                {isFeedbackActive
                  ? `Item ${totalItems} of ${totalItems} • Assessment Feedback`
                  : `Question ${currentIndex + 1} of ${totalQuestions}${currentQuestion?.type === 'written' ? ' • Written Response' : ''}`}
              </span>

              <span style={{
                fontSize: '0.75rem',
                fontWeight: 700,
                color: (
                  isFeedbackActive
                    ? isFeedbackAnswered
                    : currentQuestion?.type === 'written'
                    ? (writtenAnswers[currentQuestion.id.toString()] || '').trim().length > 0
                    : selectedOptionId !== undefined
                ) ? '#34d399' : 'rgba(255, 255, 255, 0.5)',
                background: (
                  isFeedbackActive
                    ? isFeedbackAnswered
                    : currentQuestion?.type === 'written'
                    ? (writtenAnswers[currentQuestion.id.toString()] || '').trim().length > 0
                    : selectedOptionId !== undefined
                ) ? 'rgba(16, 185, 129, 0.2)' : 'rgba(255, 255, 255, 0.08)',
                border: (
                  isFeedbackActive
                    ? isFeedbackAnswered
                    : currentQuestion?.type === 'written'
                    ? (writtenAnswers[currentQuestion.id.toString()] || '').trim().length > 0
                    : selectedOptionId !== undefined
                ) ? '1px solid rgba(16, 185, 129, 0.4)' : '1px solid rgba(255, 255, 255, 0.1)',
                padding: '0.25rem 0.75rem',
                borderRadius: '12px',
              }}>
                {(
                  isFeedbackActive
                    ? isFeedbackAnswered
                    : currentQuestion?.type === 'written'
                    ? (writtenAnswers[currentQuestion.id.toString()] || '').trim().length > 0
                    : selectedOptionId !== undefined
                ) ? '✓ Completed' : 'Pending'}
              </span>
            </div>

            {/* Content: MCQ Question, Written Question, OR Feedback Form */}
            {!isFeedbackActive && currentQuestion ? (
              <div>
                {/* Question Prompt */}
                <h3 style={{
                  fontSize: '1.35rem',
                  fontWeight: 700,
                  color: '#ffffff',
                  lineHeight: 1.5,
                  marginBottom: '1.5rem',
                }}>
                  {currentQuestion.text}
                </h3>

                {/* Optional Attached Diagram / Image */}
                {questionImage && (
                  <div style={{ marginBottom: '1.5rem', textAlign: 'center' }}>
                    <img
                      src={questionImage}
                      alt="Question diagram"
                      onClick={() => setZoomImage(questionImage)}
                      style={{
                        maxWidth: '100%',
                        maxHeight: '340px',
                        borderRadius: '12px',
                        border: '1px solid rgba(245, 197, 66, 0.3)',
                        cursor: 'zoom-in',
                        objectFit: 'contain',
                        background: '#000000',
                      }}
                    />
                    <div style={{ fontSize: '0.75rem', color: 'rgba(255, 255, 255, 0.5)', marginTop: '0.35rem' }}>
                      Click image to expand
                    </div>
                  </div>
                )}

                {/* Question Type: Written Response OR Multiple-Choice */}
                {currentQuestion.type === 'written' ? (
                  <div style={{ marginBottom: '1.5rem' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.75rem', color: 'var(--peak-gold-main)', fontSize: '0.875rem', fontWeight: 600 }}>
                      <span>✍️</span> Type your written answer below:
                    </div>
                    <textarea
                      rows={6}
                      value={writtenAnswers[currentQuestion.id.toString()] || ''}
                      onChange={(e) => handleWrittenAnswerChange(e.target.value)}
                      disabled={submitting}
                      placeholder="Type your detailed response here..."
                      style={{
                        width: '100%',
                        padding: '1rem 1.25rem',
                        borderRadius: '12px',
                        border: (writtenAnswers[currentQuestion.id.toString()] || '').trim().length > 0
                          ? '1.5px solid var(--peak-gold-main)'
                          : '1.5px solid rgba(255, 255, 255, 0.2)',
                        background: 'rgba(0, 0, 0, 0.45)',
                        color: '#ffffff',
                        fontFamily: 'inherit',
                        fontSize: '1rem',
                        lineHeight: 1.5,
                        resize: 'vertical',
                        boxSizing: 'border-box',
                        outline: 'none',
                      }}
                    />
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: '0.5rem', fontSize: '0.8125rem', color: 'rgba(255, 255, 255, 0.6)' }}>
                      <span>
                        {(writtenAnswers[currentQuestion.id.toString()] || '').trim().length > 0
                          ? '✓ Response saved (auto-sync)'
                          : 'Response required'}
                      </span>
                      <span>{(writtenAnswers[currentQuestion.id.toString()] || '').length} characters</span>
                    </div>
                  </div>
                ) : (
                  /* Multiple-Choice Options */
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
                    {currentQuestion.options.map((option, optIdx) => {
                      const isSelected = selectedOptionId === option.id;
                      const letterLabel = String.fromCharCode(65 + optIdx);

                      return (
                        <button
                          key={option.id}
                          onClick={() => handleSelectOption(option.id)}
                          disabled={submitting}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '1rem',
                            padding: '1rem 1.25rem',
                            borderRadius: '12px',
                            border: isSelected
                              ? '2px solid var(--peak-gold-main)'
                              : '1px solid rgba(255, 255, 255, 0.14)',
                            background: isSelected
                              ? 'linear-gradient(135deg, rgba(245, 197, 66, 0.18) 0%, rgba(200, 150, 40, 0.12) 100%)'
                              : 'rgba(255, 255, 255, 0.04)',
                            color: '#ffffff',
                            cursor: 'pointer',
                            textAlign: 'left',
                            transition: 'all 0.2s ease',
                            boxShadow: isSelected ? '0 0 14px rgba(245, 197, 66, 0.25)' : 'none',
                          }}
                        >
                          {/* Option Letter Tag */}
                          <div style={{
                            width: '32px',
                            height: '32px',
                            borderRadius: '8px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontWeight: 800,
                            fontSize: '0.9375rem',
                            background: isSelected ? 'var(--peak-gold-main)' : 'rgba(255, 255, 255, 0.1)',
                            color: isSelected ? '#1c0407' : 'rgba(255, 255, 255, 0.85)',
                            flexShrink: 0,
                          }}>
                            {letterLabel}
                          </div>

                          {/* Option Text */}
                          <div style={{ fontSize: '1rem', lineHeight: 1.45, flex: 1 }}>
                            {option.text}
                          </div>
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            ) : (
              /* FEEDBACK QUESTION CANVAS (INTEGRATED UNDER THE TIMER) */
              <div>
                <h3 style={{
                  fontSize: '1.35rem',
                  fontWeight: 700,
                  color: '#ffffff',
                  lineHeight: 1.5,
                  marginBottom: '1rem',
                }}>
                  {feedbackPrompt}
                </h3>
                <p style={{ fontSize: '0.875rem', color: 'rgba(255, 255, 255, 0.7)', marginBottom: '1.5rem', lineHeight: 1.5 }}>
                  This feedback question is compulsory to finalize your assessment. Your comments help us enhance Peak Pursuit.
                </p>

                <div style={{ marginBottom: '1.5rem' }}>
                  <textarea
                    rows={6}
                    value={feedback}
                    onChange={(e) => handleFeedbackChange(e.target.value)}
                    placeholder="Enter your feedback and insights here..."
                    style={{
                      width: '100%',
                      padding: '1rem 1.25rem',
                      borderRadius: '12px',
                      border: '1.5px solid rgba(245, 197, 66, 0.35)',
                      background: 'rgba(0, 0, 0, 0.4)',
                      color: '#ffffff',
                      fontFamily: 'inherit',
                      fontSize: '1rem',
                      lineHeight: 1.5,
                      resize: 'vertical',
                      boxSizing: 'border-box',
                      outline: 'none',
                    }}
                  />
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: '0.5rem', fontSize: '0.8125rem', color: 'rgba(255, 255, 255, 0.5)' }}>
                    <span>{feedback.trim().length > 0 ? '✓ Response entered' : 'Response required'}</span>
                    <span>{feedback.length} characters</span>
                  </div>
                </div>
              </div>
            )}
          </div>

          {/* Bottom Action / Navigation Controls */}
          <div style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            gap: '1rem',
            flexWrap: 'wrap',
          }}>
            {/* Previous Button */}
            <button
              onClick={() => setCurrentIndex((prev) => Math.max(0, prev - 1))}
              disabled={currentIndex === 0 || submitting}
              className="btn-secondary"
              style={{
                padding: '0.85rem 1.75rem',
                borderRadius: '12px',
                fontWeight: 700,
                opacity: currentIndex === 0 ? 0.4 : 1,
                cursor: currentIndex === 0 ? 'not-allowed' : 'pointer',
              }}
            >
              ← Previous
            </button>

            {/* Next or Review & Submit */}
            <div style={{ display: 'flex', gap: '0.75rem' }}>
              {currentIndex < totalQuestions ? (
                <button
                  onClick={() => setCurrentIndex((prev) => prev + 1)}
                  disabled={submitting}
                  className="peak-submit-btn"
                  style={{ padding: '0.85rem 2rem', fontSize: '1rem' }}
                >
                  {currentIndex === totalQuestions - 1 ? 'Go to Feedback →' : 'Next Question →'}
                </button>
              ) : null}

              {/* Review & Submit Button (always accessible) */}
              <button
                onClick={handleReviewAndSubmitClick}
                disabled={submitting}
                className="peak-submit-btn"
                style={{
                  padding: '0.85rem 2.25rem',
                  fontSize: '1rem',
                  background: remainingCount === 0
                    ? 'linear-gradient(135deg, #10b981 0%, #059669 100%)'
                    : 'linear-gradient(135deg, #d4af37 0%, #996515 100%)',
                }}
              >
                Review & Submit ({totalAnswered}/{totalItems})
              </button>
            </div>
          </div>
        </main>

        {/* Right: Question Palette & Live Status */}
        <aside className="palette-card">
          <div style={{ fontSize: '1rem', fontWeight: 800, color: '#ffffff', marginBottom: '0.75rem' }}>
            Question Palette
          </div>

          {/* Real-time Status Tracker */}
          <div className="palette-status-bar">
            <div className="palette-status-pill is-answered">
              <span>●</span> Answered: {totalAnswered}/{totalItems}
            </div>
            <div className="palette-status-pill is-remaining">
              Remaining: {remainingCount}
            </div>
          </div>

          {/* Palette Grid (1..N + Feedback) */}
          <div className="palette-grid">
            {orderedQuestions.map((q, idx) => {
              const isAnswered = q.type === 'written'
                ? (writtenAnswers[q.id.toString()] || '').trim().length > 0
                : answers[q.id.toString()] !== undefined;
              const isActive = currentIndex === idx;

              return (
                <button
                  key={q.id}
                  onClick={() => setCurrentIndex(idx)}
                  className={`palette-btn ${isAnswered ? 'is-answered' : ''} ${isActive ? 'is-active' : ''}`}
                  title={`Question ${idx + 1} (${q.type === 'written' ? 'Written' : 'MCQ'}): ${isAnswered ? 'Answered' : 'Unanswered'}`}
                >
                  {idx + 1}
                </button>
              );
            })}

            {/* Feedback Navigation Button */}
            <button
              onClick={() => setCurrentIndex(totalQuestions)}
              className={`palette-btn is-feedback ${isFeedbackAnswered ? 'is-answered' : ''} ${isFeedbackActive ? 'is-active' : ''}`}
              title="Assessment Feedback"
            >
              {totalQuestions + 1} • Feedback
            </button>
          </div>

          {/* Palette Legend */}
          <div style={{
            fontSize: '0.75rem',
            color: 'rgba(255, 255, 255, 0.65)',
            borderTop: '1px solid rgba(255, 255, 255, 0.08)',
            paddingTop: '0.85rem',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.35rem',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <span style={{ width: '10px', height: '10px', borderRadius: '3px', background: '#059669' }} />
              <span>Answered</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <span style={{ width: '10px', height: '10px', borderRadius: '3px', border: '1.5px solid var(--peak-gold-main)' }} />
              <span>Current Item</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <span style={{ width: '10px', height: '10px', borderRadius: '3px', background: 'rgba(255, 255, 255, 0.1)' }} />
              <span>Unanswered (Compulsory)</span>
            </div>
          </div>

          {/* Fast Submit Action from Palette */}
          <button
            onClick={handleReviewAndSubmitClick}
            disabled={submitting}
            className="peak-submit-btn"
            style={{
              width: '100%',
              marginTop: '1.25rem',
              padding: '0.85rem 1rem',
              fontSize: '0.9375rem',
            }}
          >
            {submitting ? 'Submitting...' : 'Review & Submit'}
          </button>
        </aside>
      </div>

      {/* MODAL 1: Incomplete Assessment Alert (when user clicks submit with missing answers) */}
      {showIncompleteAlert && (
        <div className="peak-modal-backdrop" onClick={() => setShowIncompleteAlert(false)}>
          <div className="peak-modal-box" onClick={(e) => e.stopPropagation()}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem' }}>
              <span style={{ fontSize: '1.75rem' }}>⚠️</span>
              <h3 style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--peak-gold-main)', margin: 0 }}>
                Incomplete Assessment
              </h3>
            </div>

            <p style={{ fontSize: '0.9375rem', color: 'rgba(255, 255, 255, 0.85)', lineHeight: 1.5, marginBottom: '1.25rem' }}>
              All {totalQuestions} questions and the feedback response are strictly compulsory before manual submission.
            </p>

            <div style={{
              background: 'rgba(239, 68, 68, 0.15)',
              border: '1px solid rgba(239, 68, 68, 0.35)',
              borderRadius: '12px',
              padding: '1rem',
              marginBottom: '1.5rem',
            }}>
              <div style={{ fontSize: '0.8125rem', fontWeight: 700, color: '#fca5a5', marginBottom: '0.5rem', textTransform: 'uppercase' }}>
                Unanswered Items ({missingItems.length}):
              </div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
                {missingItems.map((item, idx) => (
                  <span
                    key={idx}
                    style={{
                      background: 'rgba(0, 0, 0, 0.4)',
                      color: '#ffffff',
                      border: '1px solid rgba(239, 68, 68, 0.5)',
                      padding: '0.25rem 0.6rem',
                      borderRadius: '6px',
                      fontSize: '0.8125rem',
                      fontWeight: 600,
                    }}
                  >
                    {item}
                  </span>
                ))}
              </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.75rem' }}>
              <button
                onClick={() => setShowIncompleteAlert(false)}
                className="peak-submit-btn"
                style={{ padding: '0.75rem 1.75rem', fontSize: '0.9375rem' }}
              >
                Return to Assessment
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 2: Confirmation Modal (when all 11 items are completed) */}
      {showReviewModal && (
        <div className="peak-modal-backdrop" onClick={() => setShowReviewModal(false)}>
          <div className="peak-modal-box" onClick={(e) => e.stopPropagation()}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem' }}>
              <span style={{ fontSize: '1.75rem' }}>🏆</span>
              <h3 style={{ fontSize: '1.35rem', fontWeight: 800, color: 'var(--peak-gold-main)', margin: 0 }}>
                Submit Assessment?
              </h3>
            </div>

            <p style={{ fontSize: '0.9375rem', color: 'rgba(255, 255, 255, 0.85)', lineHeight: 1.5, marginBottom: '1.25rem' }}>
              You have completed all {totalQuestions} questions and provided feedback.
              Once submitted, your answers will be permanently recorded and graded.
            </p>

            <div style={{
              background: 'rgba(16, 185, 129, 0.15)',
              border: '1px solid rgba(16, 185, 129, 0.35)',
              borderRadius: '12px',
              padding: '1rem',
              marginBottom: '1.5rem',
              display: 'flex',
              alignItems: 'center',
              gap: '0.75rem',
            }}>
              <span style={{ color: '#34d399', fontSize: '1.25rem' }}>✓</span>
              <div style={{ fontSize: '0.875rem', color: '#ffffff', fontWeight: 600 }}>
                All {totalItems} items completed and validated.
              </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.85rem' }}>
              <button
                onClick={() => setShowReviewModal(false)}
                disabled={submitting}
                className="btn-secondary"
                style={{ padding: '0.75rem 1.5rem', borderRadius: '10px' }}
              >
                Review Answers
              </button>
              <button
                onClick={() => performSubmit('manual')}
                disabled={submitting}
                className="peak-submit-btn"
                style={{ padding: '0.75rem 2rem', fontSize: '1rem' }}
              >
                {submitting ? 'Submitting...' : 'Yes, Submit Final'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Image Zoom Modal */}
      {zoomImage && (
        <div
          className="peak-modal-backdrop"
          onClick={() => setZoomImage(null)}
          style={{ cursor: 'zoom-out' }}
        >
          <div style={{ maxWidth: '90vw', maxHeight: '90vh', position: 'relative' }}>
            <img
              src={zoomImage}
              alt="Zoomed diagram"
              style={{
                width: '100%',
                height: 'auto',
                maxHeight: '90vh',
                objectFit: 'contain',
                borderRadius: '12px',
                border: '2px solid var(--peak-gold-main)',
                boxShadow: '0 10px 40px rgba(0, 0, 0, 0.8)',
              }}
            />
          </div>
        </div>
      )}

      {/* Submitting Overlay for Timeout or Final Action */}
      {submitting && (
        <div className="peak-modal-backdrop" style={{ zIndex: 2000 }}>
          <div className="peak-modal-box" style={{ textAlign: 'center', maxWidth: '400px' }}>
            <div style={{ fontSize: '2.5rem', marginBottom: '1rem' }}>
              {submittingReason === 'timeout' ? '⏱️' : '🔒'}
            </div>
            <h3 style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--peak-gold-main)', marginBottom: '0.5rem' }}>
              {submittingReason === 'timeout' ? 'Time Expired' : 'Submitting Assessment'}
            </h3>
            <p style={{ fontSize: '0.875rem', color: 'rgba(255, 255, 255, 0.8)', margin: 0 }}>
              {submittingReason === 'timeout'
                ? 'Your time has elapsed. Finalizing and saving your answers...'
                : 'Securing and submitting your assessment to server...'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
};
