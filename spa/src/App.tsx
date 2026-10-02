import React, { useState, useEffect } from 'react';
import { getStoredToken } from './services/api';
import { LoginView } from './views/LoginView';
import { QuizEntryView } from './views/QuizEntryView';
import { QuizTakingView } from './views/QuizTakingView';
import { ResultView } from './views/ResultView';
import { StartAttemptResponse } from './types';

type Screen = 'entry' | 'taking' | 'result';

export const App: React.FC = () => {
  const [isAuthenticated, setIsAuthenticated] = useState<boolean>(() => !!getStoredToken());
  const [screen, setScreen] = useState<Screen>('entry');
  const [quizCode, setQuizCode] = useState<string>('COMP2026');
  const [activeAttemptId, setActiveAttemptId] = useState<string | null>(null);

  // Parse quiz code from URL e.g. /quiz/COMP2026 or ?code=COMP2026
  useEffect(() => {
    const path = window.location.pathname;
    const match = path.match(/\/quiz\/([A-Za-z0-9_-]+)/);
    if (match && match[1]) {
      setQuizCode(match[1].toUpperCase());
    } else {
      const urlParams = new URLSearchParams(window.location.search);
      const codeParam = urlParams.get('code');
      if (codeParam) {
        setQuizCode(codeParam.toUpperCase());
      }
    }
  }, []);

  if (!isAuthenticated) {
    return (
      <LoginView
        defaultQuizCode={quizCode}
        onLoginSuccess={() => setIsAuthenticated(true)}
      />
    );
  }

  const handleStartAttempt = (startData: StartAttemptResponse) => {
    setActiveAttemptId(startData.attempt_id);
    setScreen('taking');
  };

  const handleResumeAttempt = (attemptId: string) => {
    setActiveAttemptId(attemptId);
    setScreen('taking');
  };

  const handleSubmitted = (attemptId: string) => {
    setActiveAttemptId(attemptId);
    setScreen('result');
  };

  const handleResetToEntry = () => {
    setActiveAttemptId(null);
    setScreen('entry');
  };

  return (
    <div>
      {screen === 'entry' && (
        <QuizEntryView
          quizCode={quizCode}
          onStartAttempt={handleStartAttempt}
          onResumeAttempt={handleResumeAttempt}
        />
      )}

      {screen === 'taking' && activeAttemptId && (
        <QuizTakingView
          attemptId={activeAttemptId}
          onSubmitted={handleSubmitted}
        />
      )}

      {screen === 'result' && activeAttemptId && (
        <ResultView
          attemptId={activeAttemptId}
          onDone={handleResetToEntry}
        />
      )}
    </div>
  );
};
