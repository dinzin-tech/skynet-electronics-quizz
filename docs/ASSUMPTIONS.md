# System Assumptions & Configuration Defaults

This document records the confirmed decisions, architectural assumptions, and configuration defaults for the Employee Quiz Management System. It addresses all 17 specification items (§3 of `Agents.md`) along with the infrastructure and client clarifications.

---

## Confirmed Specifications & Policies

### 1. Employee Identity & Fields (§3 #1)
- **Primary Unique Identifier:** `employee_code` (VARCHAR(64), required, unique index).
- **Public Identifier:** `public_id` (CHAR(26) ULID, URL-safe, immutable).
- **Additional Attributes:** `name` (VARCHAR(255), required), `email` (VARCHAR(255), unique, nullable), `username` (VARCHAR(100), unique, nullable), `status` (ENUM('active', 'inactive'), default 'active').

### 2. Employee Authentication & Credential Issuance (§3 #2) — *CONFIRMED*
- **Pre-Window Authentication Allowed:** Employees can log in well before the assessment window opens.
- **CPU Storm Mitigation:** Pre-window login eliminates bcrypt CPU spikes at the moment the quiz window opens.
- **Session Tokens:** Compact HMAC-SHA256 tokens issued with a TTL spanning `assessment_window + duration + 10_minutes_buffer` (e.g., 24 hours).
- **Auth Isolation:** `POST /api/auth/login` runs in a dedicated PHP-FPM pool (`auth`, max 3 workers) with rate limiting and exponential retry headers (`429` / `503` with `Retry-After`).
- **Credential Issuance:** Passwords hashed with bcrypt (cost 10). Initial credentials set via bulk employee CSV import or generated system temporary passwords.

### 3. Bulk-Upload Template & Limits (§3 #3)
- **File Format:** CSV / XLSX (streaming parser via OpenSpout, memory bounded ≤ 150 MB).
- **Required Columns:** `employee_code,name,email,username,group,status`.
- **Duplicate Handling:** If `employee_code`, `email`, or `username` already exists, the row is skipped and recorded in a downloadable failed-rows report with a descriptive reason. No silent overwrites.
- **Limits:** Max file size ≤ 5 MB; max row count ≤ 20,000 rows. Processing runs asynchronously in background worker `jobs:worker`.

### 4. Repeat Attempts (§3 #4)
- **Policy:** Default `max_attempts = 1` (single attempt per employee per quiz).

### 5. Timer Start Event (§3 #5)
- **Trigger:** The attempt countdown begins strictly when the employee clicks **Start Quiz** on the instructions screen (`POST /api/quiz/{code}/start`). Visiting or inspecting the quiz overview page does not start the timer.

### 6. Timezone & Window Boundary (§3 #6) — *CONFIRMED*
- **Server Clock & Storage:** 100% in **UTC** across MySQL (`DATETIME(3)`) and Redis timestamps (milliseconds). Host synchronized via `chrony` NTP.
- **Display Timezone:** `Asia/Kolkata` (IST, UTC+5:30) as default `COMPANY_TZ` environment variable.
- **Window Boundary Evaluation:**
  - Start time is **inclusive** (`start_at <= server_now_utc`).
  - End time is **exclusive** (`server_now_utc < end_at`).

### 7. In-Progress Attempt vs Window End (§3 #7) — *CONFIRMED*
- **Overtime Allowance:** Active in-progress attempts are granted **up to 10 minutes** past the assessment window closing time (`end_at`).
- **Deadline Calculation:**
  $$\text{deadline\_ms} = \min(\text{started\_at} + \text{duration}, \text{end\_at} + 10\text{ minutes})$$
- **Absent Closer Timing:** The `quiz:scheduler` absent closer job will trigger at `end_at + 10 minutes + grace_ms` to guarantee that employees taking advantage of their 10-minute overtime are never prematurely marked `ABSENT`.

### 8. Navigation Policy (§3 #8) — *CONFIRMED*
- **Admin Configurable:** Configured per quiz in `quizzes.settings` JSON prior to publishing:
  - `allow_back`: boolean (allows navigating back to previous questions; if `false`, strict forward-only linear progression).
  - `allow_skip`: boolean (allows moving past a question without selecting an option).
  - `allow_review_screen`: boolean (provides a summary grid of answered/flagged questions before submit).
  - `randomize_questions`: boolean (Fisher-Yates shuffle per attempt).
  - `randomize_options`: boolean (Fisher-Yates shuffle of options per question).

### 9. Scoring Rules (§3 #9) — *CONFIRMED*
- **Admin Configurable:** Configured per quiz in `quizzes.settings` JSON prior to publishing:
  - `marks_per_correct`: float (default `1.0`).
  - `negative_marks_per_wrong`: float (default `0.0`).
  - `unanswered_penalty`: float (default `0.0`).
  - `pass_mark`: float / percentage (optional passing threshold, nullable).
  - `accuracy`: $\frac{\text{correct\_count}}{\text{total\_questions}} \times 100\%$ (consistent with the Spec's 17/20 = 85% rule).
  - `completion_time_s`: $\min(\text{submitted\_at} - \text{started\_at}, \text{duration\_seconds})$.
- **Execution:** Graded asynchronously by the `quiz:finalizer` worker.

### 10. Result Visibility (§3 #10) — *CONFIRMED*
- **Admin Configurable:** Options:
  - `score_only` (default: employee sees total score, accuracy %, and completion time).
  - `none` (employee sees submission acknowledgment only; results hidden until released).
  - `score_and_review` (employee sees score, accuracy, question breakdown, and correct answers).

### 11. Editing a Published Quiz (§3 #11)
- Once an attempt has been initiated (`IN_PROGRESS`), question and option modifications are **strictly locked**.
- Prior to any attempt being started, edits create a new immutable version snapshot (`quiz_snapshots`) and bundle.

### 12. Eligibility & User Groups (§3 #12) — *CONFIRMED*
- **User Groups Model:** Employees can be assigned to one or more user groups/departments (`groups` and `employee_groups` tables).
- **Publish Selection:** Admin selects target groups (or "All Active Employees") when publishing a quiz.
- **Roster Materialization:** `QuizPublisher` materializes `attempts` rows with `NOT_STARTED` status (chunked 500-row inserts) and populates Redis `qa:{quizId}` for eligible active employees.

### 13. Quiz Lifecycle Actions (§3 #13) — *CONFIRMED*
- **Duplicate Quiz:** Allows cloning an existing quiz, including all questions and options, into a new draft.
- **Archive Quiz:** Allows archiving past quizzes to preserve audit history while decluttering active admin listings.

### 14. ABSENT Rule (§3 #14)
- Any employee in the materialized quiz roster who has status `NOT_STARTED` when `server_now >= end_at + 10 minutes + 2 seconds` is atomically flipped to `ABSENT` via Redis Lua script and persisted to MySQL.

### 15. Resume & Offline Save Protocol (§3 #15)
- Monotonically increasing sequence number (`seq`) per attempt generated by the client.
- Client stores state in IndexedDB: `{q, o, seq, acked}`.
- Server applies saves only if `seq > stored_seq` for that question (last-write-wins).
- Reconnection / resume re-syncs any client items higher than the server's acknowledged `max_seq`.

### 16. Admin Dashboard & Reporting (§3 #16) — *CONFIRMED*
- **Dashboard 9 KPIs:** Live from Redis `qstat:{quizId}` during assessment windows:
  1. Total Employees
  2. Total Quizzes
  3. Published Quizzes
  4. Completed Attempts
  5. In-Progress Attempts
  6. Absent Employees
  7. Average Score
  8. Average Accuracy (%)
  9. Average Completion Time (s)
- **Exports:** Submissions, Results, and Performance reports generated via background `export_jobs`. Streaming CSV (`fputcsv`) and XLSX (OpenSpout) with spreadsheet formula injection neutralization (`= + - @`).

### 17. Load-Test Scenarios (§3 #17) — *CONFIRMED*
- Target capacity: 2,000 concurrent users + 1.5× headroom (3,000 users) on 2 vCPU VPS.
- Scenarios: Entry storm (2,000 logins over 120s/20s), Start burst (100 starts/s), Mixed attempt soak (save every 15–45s), Submit/expiry storm (60% manual in last 3 min, remainder expired by scheduler), Admin interference, and Chaos (Redis restart, worker kill).

### 18. Hosting & Infrastructure — *CONFIRMED*
- **Target Host:** Hostinger KVM 2 (2 vCPU, 8 GB RAM, NVMe).
- **Region:** India.
- **OS:** Ubuntu 22.04 / 24.04 LTS.
- **Edge:** Cloudflare proxy with Full-Strict SSL, static caching of bundles (`storage/bundles/*.json.gz`), and IP restoration (`CF-Connecting-IP`).
- **PHP-FPM Pools:** `hot` (10 children), `auth` (3 children), `main` (4 children).
