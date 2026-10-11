import React, { useState, useEffect } from 'react';
import { getStoredToken, api } from './services/api';
import { LoginView } from './views/LoginView';
import { QuizEntryView } from './views/QuizEntryView';
import { QuizTakingView } from './views/QuizTakingView';
import { ResultView } from './views/ResultView';
import { StartAttemptResponse } from './types';

type Screen = 'entry' | 'taking' | 'result';

function getInitialQuizCode(): string {
  const path = window.location.pathname;
  const match = path.match(/\/quiz\/([A-Za-z0-9_-]+)/);
  if (match && match[1]) {
    return match[1].toUpperCase();
  }
  const urlParams = new URLSearchParams(window.location.search);
  const codeParam = urlParams.get('code');
  if (codeParam) {
    return codeParam.toUpperCase();
  }
  return '';
}

export const App: React.FC = () => {
  const [isAuthenticated, setIsAuthenticated] = useState<boolean>(() => !!getStoredToken());
  const [screen, setScreen] = useState<Screen>('entry');
  const [quizCode, setQuizCode] = useState<string>(getInitialQuizCode);
  const [activeAttemptId, setActiveAttemptId] = useState<string | null>(null);

  // If no quiz code was in the URL, discover the active quiz dynamically
  useEffect(() => {
    if (!quizCode) {
      if (isAuthenticated) {
        api.getQuizList().then((res) => {
          const active = res.quizzes.find((q) => q.category === 'active') || res.quizzes[0];
          if (active) {
            setQuizCode(active.code);
          }
        }).catch(() => {});
      } else {
        api.getProgrammeInfo().then((res) => {
          if (res.active_quiz_code) {
            setQuizCode(res.active_quiz_code);
          }
        }).catch(() => {});
      }
    }
  }, [isAuthenticated, quizCode]);

  // Keep state synced with browser history if user presses back/forward
  useEffect(() => {
    const handlePopState = () => {
      const code = getInitialQuizCode();
      if (code) {
        setQuizCode(code);
      }
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  if (!isAuthenticated) {
    return (
      <LoginView
        defaultQuizCode={quizCode || undefined}
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

  const handleViewResult = (attemptId: string) => {
    setActiveAttemptId(attemptId);
    setScreen('result');
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
          onSelectQuizCode={(code) => setQuizCode(code)}
          onStartAttempt={handleStartAttempt}
          onResumeAttempt={handleResumeAttempt}
          onViewResult={handleViewResult}
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
