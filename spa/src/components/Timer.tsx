import React, { useEffect, useState } from 'react';
import { getServerTime } from '../services/api';

interface TimerProps {
  deadlineMs: number;
  onExpire: () => void;
}

export const Timer: React.FC<TimerProps> = ({ deadlineMs, onExpire }) => {
  const [remainingSeconds, setRemainingSeconds] = useState<number>(() => {
    const rem = Math.max(0, Math.floor((deadlineMs - getServerTime()) / 1000));
    return rem;
  });

  useEffect(() => {
    const interval = setInterval(() => {
      const now = getServerTime();
      const rem = Math.max(0, Math.floor((deadlineMs - now) / 1000));
      setRemainingSeconds(rem);

      if (rem <= 0) {
        clearInterval(interval);
        onExpire();
      }
    }, 500);

    return () => clearInterval(interval);
  }, [deadlineMs, onExpire]);

  const minutes = Math.floor(remainingSeconds / 60);
  const seconds = remainingSeconds % 60;
  const isWarning = remainingSeconds < 120 && remainingSeconds > 0;

  return (
    <div className={`timer-badge ${isWarning ? 'timer-warning' : ''}`}>
      <span style={{ fontSize: '1.125rem' }}>⏱</span>
      <span>
        {String(minutes).padStart(2, '0')}:{String(seconds).padStart(2, '0')}
      </span>
    </div>
  );
};
