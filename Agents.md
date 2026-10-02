# AGENTS.md — Employee Quiz Management System (PHP / MySQL / Redis on a 2 vCPU VPS)

You are a senior backend/infra engineer building the system described in the client's **"Employee Quiz Management System — Detailed Product & Development Requirements"** (referred to below as **the Spec**, section numbers like §16). Read this whole file first. Work **phase by phase**; do not start a phase until the previous phase's acceptance criteria pass.

**The one constraint that outranks everything else:** the system must run **smoothly with 2,000+ employees concurrently using it**. Every design decision, review comment and test is judged against that. Correctness comes first (no lost answers, no duplicate submissions, correct timers); within correctness, protect the server's two CPU cores.

---

## 0. Operating rules (every phase)

1. **Scope guard.** The Spec ends with: *"The development team should not silently add functionality that changes the agreed product scope."* Build what the Spec lists, nothing more. **Do NOT build:** live/host-synchronized quizzes, leaderboards or speed-based scoring, WebSockets/SSE, proctoring/telemetry, multi-tenancy, SSO (unless confirmed), question types other than single-choice, certificates, emails/notifications.
2. **"TO BE CONFIRMED" items** (listed in §3) are implemented as **configuration flags with the documented default**, recorded in `docs/ASSUMPTIONS.md`, and never decided silently. If a TBC item blocks a phase and has no safe default, stop and ask.
3. **Never invent framework features.** The framework (`dinzin-tech/simple-mvc`, skeleton `simple-mvc-app`) is in-house and thinly documented. Read its source before relying on any behavior. Prefer app-layer code; patch the framework only when unavoidable, minimally, with tests, and log each patch in `docs/FRAMEWORK_PATCHES.md`.
4. **Small commits**, conventional messages; run `composer test` and `composer lint` before each commit.
5. **Never claim a test or benchmark passed unless you ran it.** Paste real output. If you cannot run something, say so and give the exact command for the human.
6. **No secrets in git.** `.env` must be untracked; provide `.env.example` only.
7. **Never run provisioning/deploy scripts against a real server.** Write them idempotent, test in containers/`--dry-run`, hand over.
8. **No weak defaults.** Never create passwords equal to employee codes, never ship default admin credentials, never disable TLS verification.
9. **After each phase** write a short report: done, deviations, test results, open risks. Maintain `docs/ACCEPTANCE.md` mapping **DEV-01…DEV-23** (§39 of the Spec) to tests/evidence with PASS/FAIL.
10. Keep the **hot path dependency-free** (no framework, no Composer packages beyond what is explicitly allowed below).

---

## 1. Constraints and stack

- **Infra:** one Hostinger KVM 2 (~2 vCPU, 8 GB RAM, NVMe), Ubuntu LTS: **Nginx + PHP-FPM 8.3 + MySQL 8 + Redis 7 on the same box**, Cloudflare in front. No Octane/Swoole/RoadRunner (the framework uses singletons; not safe in long-lived workers).
- **Stack:** PHP 8.3, `dinzin-tech/simple-mvc`, MySQL 8 (InnoDB, utf8mb4), `ext-redis` (phpredis), `ext-apcu` (optional, per-worker immutable-data cache), Twig (admin UI), React + Vite + TypeScript (employee SPA). Admin-side-only extra dependency allowed: a streaming XLSX reader/writer (e.g. OpenSpout).
- **Time:** all timestamps stored in UTC; `COMPANY_TZ` env for display (default `Asia/Kolkata`, flagged as assumption). chrony/NTP must be enabled (deadlines depend on the server clock).

---

## 2. Load model (design target)

Self-paced mode has **no synchronized polling** — the timer runs locally against a server deadline — so load comes only from user actions. There are exactly **three storms** to design for:

| Storm | When | Shape (worst case, 2,000 employees, same quiz) |
|---|---|---|
| **Entry / Start** | assessment window opens | login + `GET quiz` + `start` for 2,000 users within ~20–120 s; peak ≥ 100 starts/s |
| **Answer saves** | during attempts | 20–60 questions each, a save every ~15–45 s per user ⇒ ~70–130 req/s average, bursts to ~300 req/s; ~5 % of users refresh/reconnect mid-quiz |
| **Submit / Expiry** | last minutes + deadline | ~60 % of submits in the last 3 min (peak ~150/s); then **all remaining attempts expire at nearly the same time** and must be auto-submitted and graded |

Plus: ~2,000 result views within a minute after finishing; and, **simultaneously**, a few admins refreshing the dashboard, one report export and one bulk upload — **the admin side must not be able to slow the employee side**.

Budgets: a hot request costs ≤ ~3 ms of CPU (token HMAC + 1–3 Redis calls over a unix socket, no MySQL, no framework). MySQL sees only batched writes. Everything slow (grading, reports, imports, hashing) runs off the request path.

### Design principles that follow
1. **Redis is the system of record for IN_PROGRESS attempt state**; MySQL is the durable copy via write-behind and the system of record for everything else, including every COMPLETED result.
2. **No MySQL on employee hot endpoints.**
3. **Every state transition is atomic and idempotent** (Redis Lua scripts; conditional SQL updates).
4. **Server decides time.** Deadline is computed and stored server-side; browser clocks are irrelevant.
5. **The client is a second durable copy** of saved answers (IndexedDB) and re-syncs after any mismatch, so a Redis restart cannot lose acknowledged answers.
6. **Isolate pools:** employee-hot, auth (CPU-heavy hashing), and admin each get their own PHP-FPM pool so none can starve another.

---

## 3. Spec ambiguities → configuration defaults (record in `docs/ASSUMPTIONS.md`)

Implement as per-quiz `settings` JSON or env config. **OPEN** = needs a client answer before the dependent phase is accepted.

| # | Spec says TBC | Default to implement |
|---|---|---|
| 1 | Employee fields / unique id | `employee_code` unique (required), name, email, username (unique, optional), status |
| 2 | **Employee auth method (§2, §34)** — **OPEN, blocks Phase 4** | `AuthProvider` interface + username/email + password provider. **Credential issuance (initial passwords / set-password link) is undecided — do not invent it; ask.** |
| 3 | Bulk-upload template, dup handling, limits | Columns `employee_code,name,email,username,status`; skip duplicates with a reason; no overwrite; ≤ 5 MB / ≤ 20,000 rows |
| 4 | Repeat attempts (§14) | `max_attempts = 1` |
| 5 | Timer start event (§16) | When the employee clicks **Start** after the instructions page |
| 6 | Timezone / window boundary (§25) | Window start **inclusive**, end **exclusive**, evaluated on the server clock in UTC |
| 7 | In-progress attempt vs window end | `deadline = started_at + duration` (full duration even if the window closes mid-attempt); optional flag `cap_deadline_to_window_end=false` |
| 8 | Navigation policy (§18) | Back, skip, review-all enabled (client-side flags; **not** security-enforced in v1) |
| 9 | Scoring (§26) | +1 correct, 0 wrong, 0 unanswered; no negative marking; no pass/fail; **accuracy = correct ÷ total questions** (matches the Spec's 17/20 = 85 % example); completion time = `submitted_at − started_at` (capped at duration) |
| 10 | Result visibility to employee (§1) | `score_only` (options: `none`, `score_only`, `score_and_review`) |
| 11 | Editing a published quiz | Question/option changes **blocked once any attempt has started**; before that, re-publish creates a new immutable snapshot version |
| 12 | Eligibility (§14) | All **active** employees at publish time (roster materialized), plus an admin "sync roster" action; no department filtering in v1 |
| 13 | Unpublish / archive / duplicate (§8) | Not implemented unless confirmed |
| 14 | ABSENT rule (§24) | Employee with no started attempt when the window ends |
| 15 | Resume/offline conflict handling (§20) | Per-question last-write-wins by client `seq`; see Save protocol |
| 16 | Dashboard charts/filters, historical multi-quiz performance, report columns/filenames | Only the 9 KPIs listed in §3 of the Spec; no charts beyond a simple per-quiz table; report columns documented in `docs/REPORTS.md` for client sign-off |
| 17 | Load-test scenario (§36) | The scenario defined in Phase 8 below, submitted to the client for sign-off |

---

## PHASE 0 — Audit the framework + assumptions (no feature work)

Write `docs/AUDIT.md` answering each item with file paths and evidence; mark OK / NEEDS WORK / UNKNOWN:

1. Routing: reflection/annotation scan on every request? Route cache? Measure cold vs warm bootstrap of `index.php`.
2. DB layer: lazy vs eager connection; all values bound (including `LIMIT`, `ORDER BY`, `IN`)? `orderBy` raw string? transactions; multi-row insert; `INSERT … ON DUPLICATE KEY UPDATE` via `raw()`; PDO options (exceptions, charset utf8mb4).
3. Request/Response: JSON body/response, headers, status codes, conditional requests.
4. Middleware pipeline and per-route attachment.
5. phpfastcache default driver (Files?); can it use Redis?
6. Logging destination/level/rotation; error handling; debug off in production.
7. Singletons/statics that would break if shared.
8. Console: command discovery, long-running commands, signals.
9. `composer test` / `composer lint` on a clean checkout (framework lint is PSR2, app docs say PSR12).
10. `.env` tracked in the skeleton? How is `APP_SECRET` used?

Also write `docs/ASSUMPTIONS.md` from §3 above. **Stop and report before Phase 1** if anything conflicts with this spec. **Acceptance:** both docs committed; every NEEDS WORK has a remediation assigned to a later phase.

---

## PHASE 1 — Foundation

1. `docker-compose.yml` (nginx, php-fpm, mysql, redis) so every later phase is tested on a realistic stack.
2. `config:cache` command → `config/hot.php` (returned PHP array: token secret, `kid`, redis socket, `grace_ms` (default 3000), limits). Hot code never parses dotenv.
3. `routes:cache`, lazy DB connection, JSON request/response helpers (body ≤ 64 KB, depth-limited, uniform error shape `{"error":{"code","message"}}`) — only if the audit shows they are needed.
4. `app/Hot/` (no `Core\` imports): `Token` (compact `payload.hmac_sha256`, claims `{uid, role, exp, kid}`, `hash_equals`), `Redis` wrapper (unix socket + `pconnect`), `Lua` loader (`EVALSHA` with fallback `EVAL`, scripts in `app/Hot/lua/`), `Clock`, `HotRouter` (a ~30-line regex dispatcher), `ApcuCache` helper.
5. Middleware for the full framework: `AuthToken`, `RequireRole`, per-user rate limit (Redis `INCR`+TTL; **key by user id, never by IP** — offices share NAT).
6. ULID helper (public ids; internal PKs stay `BIGINT UNSIGNED`).
7. Logging: error-level only in production; no per-request PHP logging.

**Acceptance:** unit tests for token (valid/tampered/expired/wrong kid), rate limiter, config cache; `docker compose up` gives a working stack; `composer test`/`lint` green.

---

## PHASE 2 — Database and authentication

Migrations (InnoDB, utf8mb4). Public ULID `public_id CHAR(26) UNIQUE` on anything that appears in a URL. **Entities follow Spec §33; extras are marked.**

```sql
CREATE TABLE employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  employee_code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NULL UNIQUE,
  username VARCHAR(100) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  KEY idx_status_name (status, name)
) ENGINE=InnoDB;

CREATE TABLE administrators (            -- extra: admin authentication
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE quizzes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  code VARCHAR(12) NOT NULL UNIQUE,      -- /quiz/{code}, random base32, not sequential
  title VARCHAR(255) NOT NULL, description TEXT NULL, instructions TEXT NULL,
  duration_seconds INT UNSIGNED NOT NULL,
  start_at DATETIME NOT NULL, end_at DATETIME NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  settings JSON NOT NULL,                -- randomize_questions/options, max_attempts, scoring, navigation, result_visibility, deadline policy
  current_version INT UNSIGNED NOT NULL DEFAULT 0,
  window_closed_at DATETIME NULL,        -- set when the absent job has run
  published_at DATETIME NULL, created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  KEY idx_status_window (status, start_at, end_at)
) ENGINE=InnoDB;

CREATE TABLE questions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL, display_order INT NOT NULL,
  KEY idx_quiz_order (quiz_id, display_order)
) ENGINE=InnoDB;

CREATE TABLE answer_options (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_id BIGINT UNSIGNED NOT NULL,
  option_text TEXT NOT NULL, is_correct TINYINT(1) NOT NULL DEFAULT 0, display_order INT NOT NULL,
  KEY idx_question (question_id)
) ENGINE=InnoDB;

CREATE TABLE quiz_snapshots (            -- extra: immutable published version
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
  question_count SMALLINT UNSIGNED NOT NULL,
  bundle_path VARCHAR(255) NOT NULL, bundle_sha256 CHAR(64) NOT NULL,
  answer_key JSON NOT NULL,              -- {"<question_id>": <correct_option_id>, ...}
  structure JSON NOT NULL,               -- {"<question_id>": [<option_id>, ...], ...} for validation
  created_at DATETIME NOT NULL, UNIQUE KEY uq_quiz_version (quiz_id, version)
) ENGINE=InnoDB;

CREATE TABLE attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  quiz_id BIGINT UNSIGNED NOT NULL, quiz_version INT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL, attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('NOT_STARTED','IN_PROGRESS','COMPLETED','ABSENT') NOT NULL DEFAULT 'NOT_STARTED',
  started_at DATETIME(3) NULL, deadline_at DATETIME(3) NULL,
  last_saved_at DATETIME(3) NULL, submitted_at DATETIME(3) NULL,
  submit_reason ENUM('manual','timeout','admin') NULL,
  layout JSON NULL,                      -- per-attempt shuffled order: {"q":[ids], "o":{"<qid>":[option ids]}}
  max_seq INT UNSIGNED NOT NULL DEFAULT 0,
  total_questions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  correct_count SMALLINT UNSIGNED NULL, score DECIMAL(8,2) NULL, accuracy DECIMAL(5,2) NULL,
  completion_time_s INT UNSIGNED NULL, graded_at DATETIME(3) NULL,
  UNIQUE KEY uq_attempt (quiz_id, employee_id, attempt_no),
  KEY idx_quiz_status (quiz_id, status),
  KEY idx_status_deadline (status, deadline_at),
  KEY idx_employee (employee_id)
) ENGINE=InnoDB;

CREATE TABLE attempt_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_id BIGINT UNSIGNED NOT NULL, question_id BIGINT UNSIGNED NOT NULL,
  selected_option_id BIGINT UNSIGNED NULL, is_correct TINYINT(1) NULL,
  seq INT UNSIGNED NOT NULL DEFAULT 0, answered_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_answer (attempt_id, question_id)
) ENGINE=InnoDB;
```

Also: `import_jobs` (type, status, file_path, total, imported, failed, report_path, created_by, timestamps), `export_jobs` (report_type, filters JSON, format, status, file_path, row_count, created_by, expires_at), `audit_logs` (actor, action, entity, entity_id, meta JSON, created_at). **No foreign keys on `attempt_answers`.** Seeders: 1 admin (credentials printed once, never committed), 2,500 fake employees, 2 quizzes with 40 questions each.

**Authentication:**
- `AuthProvider` interface; implement the password provider (bcrypt cost 10, tunable; `password_needs_rehash`). `POST /api/auth/login` runs in its **own FPM pool** (`auth`, max 3 children) because bcrypt costs ~50–70 ms of CPU; a login storm must queue there, not starve the hot pool. Return `429/503` + `Retry-After` when saturated; the SPA retries with jittered backoff.
- Employee token: short-lived signed token, TTL ≥ the longest window + duration (so employees can **log in before the window opens** — recommend this to the client). Admin: separate session/token with role `admin`; CSRF on admin forms.
- Revocation: Redis set checked only by admin paths and on `logout`.
- **Initial credential issuance = OPEN item #2. Stop and ask before implementing bulk password creation.** Any hashing job for imported employees must run in the low-priority worker, never in a web request.

**Acceptance:** migrations run on an empty DB and re-run safely; `EXPLAIN` evidence that `(quiz_id, employee_id, attempt_no)`, `(attempt_id, question_id)`, `(status, deadline_at)` lookups use the intended indexes; auth tests (wrong password, inactive user, role escalation, expired token).

---

## PHASE 3 — Admin core (Spec §3–§13, P0) — Twig

- **Employees:** searchable paginated list; add/edit/delete (soft-delete → `inactive` when attempts exist, hard delete otherwise); validations for required fields, duplicate `employee_code`, duplicate email/username, bad formats; success/error feedback.
- **Quizzes:** create/edit/view/configure; title, description, instructions, duration, window start/end, status, settings (randomization flags, etc.); questions and options CRUD, mark the correct option, **reorder**; validations (every question has ≥ 2 options and exactly one correct).
- **Publish** (`QuizPublisher`): validate → build an immutable `quiz_snapshots` row → write the **bundle** `storage/bundles/{quizId}-v{n}-{sha256}.json` (+ `.gz`, `.br`) containing questions and options **without `is_correct`** → load into Redis (below) → **materialize the roster** (`attempts` rows with `NOT_STARTED` for every eligible employee; chunked inserts of 500) → show the shareable URL `/quiz/{code}` with copy/share buttons. Publishing and roster work run in a worker job with a progress indicator; the admin page never blocks on it.
- **Redis warm-up** (`quiz:warm {quizId}`; also auto-run at T−10 min by the scheduler and idempotent):
  - `quiz:{code}` → hash: `id, version, status, start_ms, end_ms, duration_s, title, instructions…, settings JSON, total_questions`
  - `key:{quizId}:{ver}` → hash `question_id → correct_option_id` (**never sent to any client**)
  - `qa:{quizId}` → hash `employee_id → attempt public id`
  - `att:{aid}` → hash per attempt (see Phase 4) initialized `NOT_STARTED`
- Business logic lives in `app/services/`; controllers stay thin.

**Acceptance:** DEV-02, DEV-03, DEV-04 tests; publish of a 2,000-employee roster completes in a worker without web timeouts; bundle contains no correctness data (test greps for `is_correct`/correct ids).

---

## PHASE 4 — Quiz engine (the critical phase, P0)

### 4.1 Entry points (Nginx maps these to `public/hot.php`, not the framework)
`GET /api/quiz/{code}` · `POST /api/quiz/{code}/start` · `GET /api/attempts/{aid}` · `GET /api/attempts/{aid}/bundle` · `PUT /api/attempts/{aid}/answers` · `POST /api/attempts/{aid}/submit` · `GET /api/time`.
Every hot response carries `X-Server-Time: <ms>` so clients refine their clock offset for free.

### 4.2 Redis schema
| Key | Type | Content |
|---|---|---|
| `att:{aid}` | hash | `eid, quiz_id, ver, status, started_ms, deadline_ms, submitted_ms, reason, layout, max_seq, total, score, correct, accuracy, time_s, graded` |
| `ans:{aid}` | hash | `question_id → "option_id|seq|ts_ms"` |
| `deadlines` | zset | `aid → deadline_ms` (only IN_PROGRESS) |
| `fq` | list | attempt ids awaiting finalize/grade |
| `dirty` | set | `"aid:question_id"` pending write-behind |
| `dirty_att` | set | `aid` whose attempt row changed (start/submit) |
| `qstat:{quizId}` | hash | `started, in_progress, completed, absent, sum_score, sum_accuracy, sum_time_s` (for the dashboard) |
| `rl:{uid}:{bucket}` | string | rate-limit counters |

All attempt keys get a TTL (e.g. 48 h) **after** the attempt is COMPLETED and graded and persisted. Redis: `maxmemory-policy noeviction`, AOF `everysec`, unix socket.

### 4.3 Endpoint contracts
- **`GET /api/quiz/{code}`** (token required): loads quiz meta (APCu → Redis), checks **published → eligible (`qa`) → window (`start ≤ now < end`) → attempt state**, returns `{state: not_open|closed|ineligible|can_start|resumable|completed|absent, meta (title, instructions, duration, total_questions, opens_at/closes_at), server_now_ms, attempt?: {id, status}}`. On Redis miss for the attempt: **rehydrate from MySQL** under a short lock (`SET NX PX`), never stampede MySQL.
- **`POST …/start`**: validate state and window in PHP; build the shuffled `layout` (server-side `random_int` Fisher–Yates over question ids and each question's option ids, only if the quiz flags are on; otherwise display order); run **START Lua** atomically: if already `IN_PROGRESS` → return existing state (this is also how double-click and "start in two tabs" resolve); if `COMPLETED`/`ABSENT` → return that; if `NOT_STARTED` → set `IN_PROGRESS, started_ms=now, deadline_ms=now+duration` (apply the deadline policy), store `layout`, `ZADD deadlines`, `SADD dirty_att`, `HINCRBY qstat`. Response: `{attempt_id, deadline_ms, server_now_ms, layout, bundle_url, answers: {}}`.
- **`GET /api/attempts/{aid}`** (resume/state): verify `att.eid == token.uid` (**ownership check on every attempt endpoint — never trust the id alone**); returns status, `deadline_ms`, `server_now_ms`, `layout`, saved answers (`ans`), `max_seq`; and, when COMPLETED + graded + visibility allows, the result fields. `404` (not `403`) for other people's attempts.
- **`GET …/bundle`**: ownership + status check in PHP (≈1 ms), then respond with `X-Accel-Redirect: /_bundles/{file}`; Nginx serves the file (gzip/brotli static, `ETag`). PHP never reads the payload. The bundle is questions + options only.
- **`PUT …/answers`** — body `{items:[{q, o, seq}, …]}` (≤ 100 items, body ≤ 8 KB). PHP validates `q` and `o` against `structure` (APCu-cached per quiz version; fallback Redis), then **SAVE Lua**, atomically: reject `409 {code:"not_in_progress"}` unless `IN_PROGRESS`; reject `409 {code:"deadline_passed"}` if `now > deadline_ms + grace_ms`; for each item apply it **only if `seq` > stored seq for that question** (last-write-wins, idempotent); `SADD dirty`; update `max_seq`; `last_saved`. Response `{ok:true, max_seq, server_now_ms}`.
- **`POST …/submit`** — SUBMIT Lua, atomic and idempotent: if status ≠ `IN_PROGRESS` return current status (double-click / concurrent submit ⇒ exactly one winner); else set `COMPLETED`, `submitted_ms=min(now, deadline_ms)`, `reason=manual`, `ZREM deadlines`, `RPUSH fq`, `SADD dirty_att`, `HINCRBY qstat`. Respond `{status:"COMPLETED"}` immediately; **grading is asynchronous**. The Spec's "persist final answers, lock the attempt, prevent duplicate submission" is satisfied by the Lua transition + `ans` hash + finalizer.
- Per-user rate limit on `PUT answers` (e.g. 20 req / 10 s per token) → `429` + `Retry-After`.

### 4.4 Save protocol (client ↔ server) — the "never lose an acknowledged answer" rule
Each answer change gets a monotonically increasing client `seq`. The client stores `{q, o, seq, acked}` in IndexedDB first, then sends batches (debounce ~500 ms; plus a flush every 10 s for unacked items). The response's `max_seq` tells the client what the server has. **If the server's `max_seq` (from any response, or from `GET attempt` on resume/reconnect) is lower than the client's highest acked seq, the client re-sends everything above it.** This heals Redis restarts, lost responses and rehydration from older MySQL data.

### 4.5 Workers (long-running console commands, systemd, graceful `SIGTERM`)
- **`quiz:scheduler`** (tick ~1 s): (a) **expirer** — `ZRANGEBYSCORE deadlines -inf (now−grace)` in batches of 500 → run SUBMIT Lua with `reason=timeout` → `fq`; (b) **warm** — at `start_at − 10 min`; (c) **absent closer** — when `now ≥ end_at + 2 s` and `window_closed_at IS NULL`: drain `dirty_att` first, then for each attempt in `qa:{quizId}` run a Lua `NOT_STARTED → ABSENT` flip (a start racing the boundary wins or loses atomically), bulk-update MySQL for the flipped ids only, set `window_closed_at`. Never `UPDATE … WHERE status='NOT_STARTED'` blindly: MySQL may lag Redis.
- **`quiz:flusher`** (tick ~2 s): `SPOP dirty` up to 1,000 → batch-read `ans` → multi-row `INSERT … ON DUPLICATE KEY UPDATE` into `attempt_answers` (guard with `seq` so older data never overwrites newer); `SPOP dirty_att` → batch-update `attempts` (status, started_at, deadline_at, layout, last_saved_at, max_seq, submitted_at, submit_reason). Retries with backoff; failures to a dead-letter list + log; **MySQL downtime must not affect the hot path.**
- **`quiz:finalizer`**: `BLPOP fq` → batches ≤ 200 → for each attempt read `ans` + the answer key (Redis `key:*`, MySQL fallback) → compute `correct_count`, `score`, `accuracy`, `completion_time_s` per the scoring config → write `attempt_answers.is_correct` and the `attempts` result fields in one transaction per batch (idempotent via unique keys and `WHERE graded_at IS NULL`) → set result fields + `graded=1` in `att:{aid}` → update `qstat` sums → apply TTLs. Crash-safe: a reconciler pass re-enqueues any COMPLETED attempt with `graded_at IS NULL` older than 60 s.
- **`jobs:worker`** (runs under `nice -n 19`, `ionice -c3`): imports, exports, hashing, roster materialization.

### 4.6 Required invariants (write a test for each)
- No MySQL connection is opened by `hot.php` (assert `Threads_connected` unchanged across 10,000 hot requests).
- Randomized option order never affects grading (grading is by option **id** against the key); layout is stable across refresh/resume and stored per attempt.
- Correct answers/keys never appear in any client response before submission (bundle, start, resume, save).
- Answers saved right before the deadline (within grace) are counted; after `deadline + grace` they are rejected.
- The Spec's edge-case table (§37) — each row an automated test: refresh mid-quiz; internet loss then resume; device shutdown then resume (rehydrate); timer expiry auto-submit with the browser closed; never-started ⇒ ABSENT; double-click submit; refresh after submit; **concurrent submit requests + timer expiry race**; randomized mapping; invalid bulk upload.
- Redis flushed mid-quiz ⇒ attempts rehydrate from MySQL, clients re-sync by `max_seq`, no duplicate attempts are created.
- Finalizer/flusher/scheduler restarts mid-batch lose nothing and double-count nothing.

**Acceptance:** DEV-01, 08–17 pass with evidence; invariants above green; a single-node micro-benchmark of each hot endpoint recorded in `docs/PERF_NOTES.md` (req/s and CPU ms per request).

---

## PHASE 5 — Employee SPA (React + Vite + TypeScript, served same-origin)

Check first whether the owner has a separate frontend skeleton to reuse; if so, use it and note it.

- Screens: login → quiz entry (`/quiz/{code}`: state, instructions, total questions, duration) → quiz screen (question text, options, current/total, **remaining time**, previous/next, question navigator with answered/unanswered, optional review/confirm-submit showing answered count) → result screen (per `result_visibility`) → clear states for not-yet-open, closed, ineligible, already completed, resumable.
- **Timer:** computed from `deadline_ms` and a clock offset refined from every response's `X-Server-Time`/`server_now_ms`; local countdown only; at 0 lock the UI and call submit (best effort — the server expirer is the guarantee).
- **Thundering-herd courtesy:** if the page is open before the window, show a countdown; when it reaches 0, wait a random 0–8 s before auto-fetching quiz state (never delay a manual click). Poll nothing else.
- **Saves:** per §4.4 (IndexedDB queue, debounce, jittered exponential backoff, honor `Retry-After`, `429/503` handling, resync on `max_seq` mismatch, flush on `visibilitychange`/`online`). Optimistic "saved" indicator that reflects server ack.
- Page refresh restores via `GET attempt`; never creates a new attempt. Duplicate-click guards on Start and Submit.
- Budget: ≤ ~180 KB gzipped initial JS; route-level code splitting; keyboard accessible; sufficient contrast.
- Playwright e2e for every row of the Spec's edge-case table that has a browser component.

**Acceptance:** DEV-01, 06–13 demonstrable end to end; offline-then-online scenario passes.

---

## PHASE 6 — Admin reporting (Spec §3, §5, §27–§32, P1)

- **Dashboard (9 KPIs):** total employees, total quizzes, published quizzes, completed attempts, in-progress attempts, absent employees, average score, average accuracy, average completion time. Running quizzes read **Redis `qstat`**; otherwise MySQL aggregates cached 5–10 s. **No heavy aggregate may run uncached while a quiz is live.**
- **Submission/results table** (Spec §27 layout: Employee, Quiz, Score `17/20`, Accuracy `85%`, Time `18m`, Status): keyset pagination, search/filter by quiz/status, "—" for absent/ungraded, elapsed time for IN_PROGRESS. MySQL-backed (≤ a few seconds behind Redis).
- **Analytics (§28–§30):** per-quiz eligible/started/completed/in-progress/absent, avg/high/low accuracy, avg/fastest/slowest completion time; per-employee-per-quiz performance view.
- **Reports (§31–§32):** submission, result, performance. **Async export jobs** (`export_jobs`) in the low-priority worker; stream rows in chunks of 5,000; CSV via `fputcsv`, XLSX via a streaming writer; stable documented column order in `docs/REPORTS.md`; export matches the filters shown on screen; **neutralize spreadsheet formula injection** (prefix cells starting with `= + - @`); files stored outside the web root, downloaded through an authorized endpoint, auto-expired.
- **Bulk employee upload (§5):** Employees → Bulk Upload → select file → **validate** → **preview** (counts + first 50 rows + errors) → **import** → summary (`Total / Imported / Failed`) + downloadable failed-rows CSV with a reason per row. File validation: extension + content sniffing + size/row limits; streaming parse; chunked transactions (200 rows); invalid rows never block or corrupt valid ones; duplicate handling per §3 #3; runs as a job with progress.
- Admin FPM pool is small (max 4 children) so these screens cannot starve employees.

**Acceptance:** DEV-05, 18–21; dashboard p95 < 1 s **while the Phase 8 load test is running**; export of 20,000 rows does not exceed ~150 MB RSS.

---

## PHASE 7 — Infrastructure (`ops/`; never applied to real servers by you)

- **Nginx:** `location ~ \.php$ { return 404; }` (only the three entry scripts run via explicit locations); `/api/(quiz|attempts|time)/` → `hot.php` on the `hot` pool; `/api/auth/login` → `index.php` on the `auth` pool with a modest `limit_req` keyed by **username/token, not IP**; other `/api/`, `/admin/` → `index.php` on the `main` pool; `internal` location `/_bundles/` with `sendfile`, `gzip_static`/`brotli_static`; SPA + hashed assets with immutable caching; `limit_req` on `PUT answers` keyed by token; security headers; `/healthz`.
- **PHP-FPM pools:** `hot` (`pm=static`, ~10 children, `listen.backlog=1024`, `request_terminate_timeout=5s`), `auth` (~3), `main` (dynamic, ~4). OPcache `validate_timestamps=0`, 128 MB, interned strings 16 MB; `cgi.fix_pathinfo=0`; APCu enabled.
- **Redis:** unix socket + password, `maxmemory 1gb`, `noeviction`, AOF `everysec`.
- **MySQL:** `innodb_buffer_pool_size` ≈ 3 GB, `max_connections` ≈ 100, `innodb_flush_log_at_trx_commit=1` (document the `2` trade-off, do not enable silently), slow log `long_query_time=0.5`, `utf8mb4`.
- **Kernel:** `net.core.somaxconn=4096`, `tcp_max_syn_backlog=4096`, `LimitNOFILE=65536`.
- **systemd:** `quiz-scheduler`, `quiz-flusher`, `quiz-finalizer`, `quiz-jobs` (`Restart=always`). `ops/deploy.sh` (pull → composer install --no-dev -o → `config:cache` → `routes:cache` → migrate → build SPA → reload FPM → restart workers → smoke test) and an idempotent `ops/provision.sh`.
- **Security baseline:** HTTPS only (Cloudflare Full-strict + origin cert), firewall limited to 80/443 (+ restricted SSH), MySQL/Redis listen locally only, secrets via env/`.env` with 600 perms, `fail2ban` for SSH.
- **Runbook** (`docs/RUNBOOK.md`): quiz-day checklist (warm-up verified at T−10 min, workers running, Redis/MySQL healthy, disk space), deploy/rollback, Redis restart procedure, "workers behind" procedure, restoring from backup, **daily MySQL backups** with a tested restore.

**Acceptance:** `nginx -t`, `php-fpm -t` pass in containers built from `ops/`; compose stack mirrors production topology.

---

## PHASE 8 — Concurrency, failure and security testing (Spec §36, DEV-22, DEV-23)

Write `loadtest/SCENARIO.md` (for client sign-off — the Spec requires the scenario be defined before performance acceptance), then k6 scripts. Run against the compose stack first, then (human-approved) a staging VPS with the same spec.

**Scenarios** (each also run at **1.5× = 3,000 users** to prove "2k+" headroom):
1. **Entry storm:** 2,000 users log in and `GET quiz` over 120 s (and a harsher variant: all within 20 s).
2. **Start burst:** 2,000 starts with peak ≥ 100/s.
3. **Mixed attempt:** 2,000 concurrent attempts, 40 questions, a save per user every 15–45 s, 5 % random refresh/reconnect (resume), 30 min soak.
4. **Submit/expiry:** 60 % manual submits in the last 3 min with double-click duplicates; the rest left to the expirer; verify every attempt is graded within 60 s of its deadline.
5. **Results:** 2,000 `GET attempt` in 60 s after completion.
6. **Admin interference (concurrent with 3–5):** 5 admins refreshing the dashboard every 5 s, one 20k-row export, one 5k-row bulk upload.
7. **Chaos:** restart Redis mid-attempt; stop MySQL for 60 s; kill each worker mid-batch; flush Redis entirely.

**Pass criteria (starting targets — record actuals, never edit targets to pass):**
- Correctness: **0 lost acknowledged saves** (k6 keeps a ledger of 2xx-acked saves and compares with `attempt_answers` after grading); **0 duplicate submissions**; 0 duplicate attempts; every graded result equals an independent re-grade of the ledger.
- Latency under peak: start p99 < 500 ms; save p99 < 300 ms; submit p99 < 500 ms; `GET attempt`/quiz p99 < 300 ms; admin dashboard p95 < 1 s.
- Error rate < 0.1 % (excluding intentional `429`s); no FPM "max_children reached" in logs; `Threads_connected` < 50; Redis average command latency < 1 ms; sustained CPU < 85 %; write-behind lag < 5 s; finalize lag < 60 s.
- Chaos: zero lost acknowledged answers after Redis restart/flush (client re-sync), no stuck attempts after any worker kill.

Also run the security checklist for DEV-22 as automated tests: IDOR attempts on every attempt/result endpoint, admin routes without admin role, tampered/expired tokens, SQL injection probes, oversized/odd JSON bodies, upload abuse (wrong types, huge files, zip bombs), CSV/XLSX formula injection in exports, correct-answer leakage scan across all employee responses.

Write `docs/LOADTEST.md` with raw numbers, bottleneck analysis and tuning applied. If a target fails, say so, identify the bottleneck (FPM children, Redis, Nginx, kernel backlog, MySQL flush), and propose a fix. Documented fallback if the hot router itself is the bottleneck: move only the `PUT answers` endpoint to a minimal dedicated script/OpenResty handler.

**Also provide** a fallback `AttemptStore` implementation that writes straight to MySQL (no Redis state) behind the same interface and benchmark it against the Redis write-behind store, so the persistence choice is evidence-based.

---

## Security rules (enforce in review)

- Employees can access **only their own** attempt/results; every attempt endpoint checks ownership server-side; unknown/foreign ids return 404; ids exposed are ULIDs.
- Admin functionality requires an admin role; every admin mutation is audit-logged.
- Correct answers are never sent to the browser before submission (and per `result_visibility` after).
- All SQL via bound parameters; whitelist anything interpolated (`ORDER BY`, column names).
- Validate all input server-side (bodies, ids, enums, lengths); validate uploads and imported rows.
- Credentials hashed with bcrypt (cost tunable); secrets only in env; HTTPS in production; security headers; CSRF on admin forms; cookies `HttpOnly; Secure; SameSite=Lax`.
- Minimize PII (code, name, email, username only).

---

## Definition of done

- [ ] Phases 0–8 acceptance met with evidence in `docs/`; `docs/ACCEPTANCE.md` shows DEV-01…DEV-23 with PASS/FAIL and links.
- [ ] CI green: `composer test`, `composer lint`, frontend tests, Playwright e2e.
- [ ] `docs/ASSUMPTIONS.md` updated with the client's answers to every OPEN/TBC item.
- [ ] `docs/RUNBOOK.md`, `docs/REPORTS.md`, `docs/LOADTEST.md`, `docs/openapi.yaml` (matching the code).
- [ ] One-page "known limits and scaling path": single-box capacity; next steps are separate Redis/MySQL hosts, then a second web node, then read replicas for reporting.

## Questions to put to the human / client now

1. **Employee login method and how initial credentials are issued** (blocks Phase 4). Strong recommendation: allow login **before** the window opens; password hashing is the one CPU-heavy step in a 2,000-user storm.
2. Company timezone and confirmation of the window-boundary rule (§3 #6).
3. Repeat-attempt policy, and whether in-progress attempts may run past the window end (§3 #4, #7).
4. Scoring rules: negative marking, pass/fail, unanswered handling (§3 #9) and what employees may see after submitting (#10).
5. Navigation policy (back/skip/review) and whether it must be server-enforced.
6. Who is eligible for a quiz (all active employees vs. a selected list) and whether unpublish/archive/duplicate are wanted.
7. Sign-off on `loadtest/SCENARIO.md` and the report column definitions.
8. Hostinger region, OS image, and whether Cloudflare is confirmed.