export interface AuthUser {
  id: number;
  employee_code: string;
  name: string;
  email?: string;
  username?: string;
  role: 'employee' | 'admin';
}

export interface AuthResponse {
  token: string;
  user: AuthUser;
  expires_in: number;
}

export interface NavigationSettings {
  allow_back?: boolean;
  allow_skip?: boolean;
  allow_review_screen?: boolean;
  randomize_questions?: boolean;
  randomize_options?: boolean;
}

export interface QuizMeta {
  code: string;
  title: string;
  description?: string;
  instructions?: string;
  feedback_question?: string | null;
  duration_seconds: number;
  total_questions: number;
  week_number?: number;
  week_label?: string;
  opens_at_ms: number;
  closes_at_ms: number;
  navigation: NavigationSettings;
}

export interface QuizStateResponse {
  state: 'not_open' | 'closed' | 'ineligible' | 'can_start' | 'resumable' | 'completed' | 'absent';
  attempt?: {
    id: string;
    status: string;
  };
  meta: QuizMeta;
  server_now_ms: number;
  message?: string;
}

export interface AnswerOption {
  id: number;
  text: string;
  display_order: number;
}

export interface Question {
  id: number;
  text: string;
  image_url?: string | null;
  image_path?: string | null;
  display_order: number;
  type?: 'mcq' | 'written';
  options: AnswerOption[];
}

export interface QuizBundle {
  quiz_id: number;
  public_id: string;
  code: string;
  title: string;
  description?: string;
  instructions?: string;
  feedback_question?: string | null;
  duration_seconds: number;
  version: number;
  week_number?: number;
  week_label?: string;
  total_questions: number;
  settings: {
    navigation: NavigationSettings;
  };
  questions: Question[];
}

export interface AttemptLayout {
  q: number[];
  o: Record<string, number[]>;
}

export interface StartAttemptResponse {
  attempt_id: string;
  status: string;
  started_ms: number;
  deadline_ms: number;
  portal_closing_ms?: number;
  layout: AttemptLayout;
  bundle_url: string;
  answers: Record<string, { selected_option_id: number; text_answer?: string | null; seq: number }>;
  server_now_ms: number;
}

export interface HelplineConfig {
  enabled?: boolean;
  phone?: string;
  email?: string;
  text?: string;
  image_path?: string | null;
}

export interface AttemptStateResponse {
  attempt_id: string;
  quiz_code?: string;
  quiz_title?: string;
  week_number?: number;
  week_label?: string;
  status: string;
  started_ms: number;
  deadline_ms: number;
  portal_closing_ms?: number;
  submitted_ms: number;
  max_seq: number;
  layout: AttemptLayout | null;
  answers: Record<string, { selected_option_id: number; text_answer?: string | null; seq: number }>;
  feedback?: string | null;
  helpline?: HelplineConfig | null;
  server_now_ms: number;
  result?: {
    score: number;
    correct_count: number;
    total_questions: number;
    accuracy: number;
    completion_time_s: number;
  };
}

export interface SaveItem {
  q: number;
  o?: number | null;
  t?: string | null;
  seq: number;
  ts?: number;
}

export interface QuizListItem {
  id: number;
  code: string;
  title: string;
  description?: string;
  duration_seconds: number;
  week_number?: number;
  week_label?: string;
  opens_at_ms: number;
  closes_at_ms: number;
  attempt_status: string;
  attempt_id?: string | null;
  category: 'active' | 'upcoming' | 'previous' | 'completed';
}

export interface ProgrammeWeek {
  week_number: number;
  label: string;
  status: 'active' | 'upcoming' | 'completed';
  code?: string | null;
  title: string;
}

export interface ProgrammeInfo {
  programme_title: string;
  organization: string;
  total_weeks: number;
  current_week: number;
  current_week_label: string;
  status_badge: string;
  duration_tagline: string;
  weeks: ProgrammeWeek[];
  active_quiz_code?: string | null;
  server_now_ms: number;
}

