import React, { useEffect, useState } from 'react';
import { getServerTime } from '../services/api';

interface DualTimerProps {
  deadlineMs: number;
  portalClosingMs?: number;
  onExpire: () => void;
  compact?: boolean;
}

function formatDuration(totalSeconds: number): string {
  if (totalSeconds <= 0) return '00:00';
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (hours > 0) {
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

export const DualTimer: React.FC<DualTimerProps> = ({
  deadlineMs,
  portalClosingMs,
  onExpire,
  compact = false,
}) => {
  const [quizRemainingSec, setQuizRemainingSec] = useState<number>(() => {
    return Math.max(0, Math.floor((deadlineMs - getServerTime()) / 1000));
  });

  const [portalRemainingSec, setPortalRemainingSec] = useState<number | null>(() => {
    if (!portalClosingMs || portalClosingMs <= 0) return null;
    return Math.max(0, Math.floor((portalClosingMs - getServerTime()) / 1000));
  });

  useEffect(() => {
    let fired = false;

    const interval = setInterval(() => {
      const now = getServerTime();
      const quizSec = Math.max(0, Math.floor((deadlineMs - now) / 1000));
      const portalSec =
        portalClosingMs && portalClosingMs > 0
          ? Math.max(0, Math.floor((portalClosingMs - now) / 1000))
          : null;

      setQuizRemainingSec(quizSec);
      setPortalRemainingSec(portalSec);

      const isExpired = quizSec <= 0 || (portalSec !== null && portalSec <= 0);
      if (isExpired && !fired) {
        fired = true;
        clearInterval(interval);
        onExpire();
      }
    }, 500);

    return () => clearInterval(interval);
  }, [deadlineMs, portalClosingMs, onExpire]);

  const isQuizUrgent = quizRemainingSec < 120 && quizRemainingSec > 0;
  const isPortalUrgent = portalRemainingSec !== null && portalRemainingSec < 120 && portalRemainingSec > 0;

  return (
    <div className={`dual-timer-container ${compact ? 'is-compact' : ''}`}>
      {/* Timer 1: Quiz time left */}
      <div className={`dual-timer-pill ${isQuizUrgent ? 'is-urgent' : ''}`} title="Time remaining for this quiz attempt">
        <div className="dual-timer-icon">⏱</div>
        <div className="dual-timer-content">
          <span className="dual-timer-label">Quiz time left</span>
          <span className="dual-timer-value">{formatDuration(quizRemainingSec)}</span>
        </div>
      </div>

      {/* Timer 2: Portal closes in (if portal closing time is configured) */}
      {portalRemainingSec !== null && (
        <div className={`dual-timer-pill is-portal ${isPortalUrgent ? 'is-urgent' : ''}`} title="Portal assessment window closing countdown">
          <div className="dual-timer-icon">⏳</div>
          <div className="dual-timer-content">
            <span className="dual-timer-label">Portal closes in</span>
            <span className="dual-timer-value">{formatDuration(portalRemainingSec)}</span>
          </div>
        </div>
      )}
    </div>
  );
};
