import {
  AuthResponse,
  QuizStateResponse,
  StartAttemptResponse,
  AttemptStateResponse,
  QuizBundle,
  SaveItem,
} from '../types';

let clockOffsetMs = 0;
const TOKEN_KEY = 'corp_quiz_token';
const USER_KEY = 'corp_quiz_user';

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setStoredAuth(auth: AuthResponse): void {
  localStorage.setItem(TOKEN_KEY, auth.token);
  localStorage.setItem(USER_KEY, JSON.stringify(auth.user));
}

export function clearStoredAuth(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

export function getStoredUser() {
  const raw = localStorage.getItem(USER_KEY);
  return raw ? JSON.parse(raw) : null;
}

export function getServerTime(): number {
  return Date.now() + clockOffsetMs;
}

function updateClockOffset(serverTimeMs: number): void {
  if (serverTimeMs && !isNaN(serverTimeMs)) {
    // Single-sample or smoothed offset
    clockOffsetMs = serverTimeMs - Date.now();
  }
}

async function request<T>(
  path: string,
  options: RequestInit = {},
  retries = 3
): Promise<T> {
  const token = getStoredToken();
  const headers = new Headers(options.headers || {});

  if (token && !headers.has('Authorization')) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  if (options.body && typeof options.body === 'string' && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const config: RequestInit = {
    ...options,
    headers,
  };

  try {
    const res = await fetch(path, config);

    // Sync clock offset from X-Server-Time header
    const serverHeader = res.headers.get('X-Server-Time');
    if (serverHeader) {
      updateClockOffset(parseInt(serverHeader, 10));
    }

    if (res.status === 304) {
      return {} as T;
    }

    // Handle 429 Rate Limit and 503 Server Busy with jittered backoff
    if ((res.status === 429 || res.status === 503) && retries > 0) {
      const retryAfterHeader = res.headers.get('Retry-After');
      const waitSeconds = retryAfterHeader ? parseInt(retryAfterHeader, 10) : 2;
      const jitterMs = Math.floor(Math.random() * 500);
      await new Promise((r) => setTimeout(r, waitSeconds * 1000 + jitterMs));
      return request<T>(path, options, retries - 1);
    }

    const data = await res.json();
    if (data.server_now_ms) {
      updateClockOffset(data.server_now_ms);
    }

    if (!res.ok) {
      const errorMsg = data?.error?.message || data?.message || `Request failed with status ${res.status}`;
      const err = new Error(errorMsg);
      (err as any).status = res.status;
      (err as any).code = data?.error?.code || data?.code;
      throw err;
    }

    return data as T;
  } catch (error: any) {
    if (error.status) {
      throw error;
    }
    // Network failure
    if (retries > 0 && options.method === 'GET') {
      await new Promise((r) => setTimeout(r, 1000 + Math.random() * 500));
      return request<T>(path, options, retries - 1);
    }
    throw error;
  }
}

export const api = {
  async login(identifier: string): Promise<AuthResponse> {
    const res = await request<AuthResponse>('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({ identifier }),
    });
    setStoredAuth(res);
    return res;
  },

  async getQuizList(): Promise<{ quizzes: import('../types').QuizListItem[]; server_now_ms: number }> {
    return request<{ quizzes: import('../types').QuizListItem[]; server_now_ms: number }>('/api/quiz/list');
  },

  async getQuizMeta(code: string): Promise<QuizStateResponse> {
    return request<QuizStateResponse>(`/api/quiz/${encodeURIComponent(code)}`);
  },

  async startAttempt(code: string): Promise<StartAttemptResponse> {
    return request<StartAttemptResponse>(`/api/quiz/${encodeURIComponent(code)}/start`, {
      method: 'POST',
    });
  },

  async getAttempt(aid: string): Promise<AttemptStateResponse> {
    return request<AttemptStateResponse>(`/api/attempts/${encodeURIComponent(aid)}`);
  },

  async fetchBundle(aid: string): Promise<QuizBundle> {
    return request<QuizBundle>(`/api/attempts/${encodeURIComponent(aid)}/bundle`);
  },

  async saveAnswers(
    aid: string,
    items: SaveItem[]
  ): Promise<{ ok: boolean; max_seq: number; server_now_ms: number }> {
    return request<{ ok: boolean; max_seq: number; server_now_ms: number }>(
      `/api/attempts/${encodeURIComponent(aid)}/answers`,
      {
        method: 'PUT',
        body: JSON.stringify({ items }),
      }
    );
  },

  async submitAttempt(
    aid: string,
    reason: 'manual' | 'timeout' = 'manual'
  ): Promise<{ ok: boolean; submitted: boolean; submitted_ms: number }> {
    return request<{ ok: boolean; submitted: boolean; submitted_ms: number }>(
      `/api/attempts/${encodeURIComponent(aid)}/submit`,
      {
        method: 'POST',
        body: JSON.stringify({ reason }),
      }
    );
  },
};
