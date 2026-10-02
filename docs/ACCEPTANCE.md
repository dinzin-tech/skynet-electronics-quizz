# Acceptance Criteria Verification Matrix (DEV-01 to DEV-23)

This document tracks the acceptance criteria defined in §39 of the Client Specification across all implementation phases.

| ID | Description / Area | Phase | Target Verification | Status | Evidence / Notes |
|---|---|---|---|---|---|
| **DEV-01** | Employee Auth & Pre-Window Access | Phase 2, 5 | Unit tests & SPA flow | PASS (Backend) | AuthProvider bcrypt + token verification + role escalation blocked + 2,500 seeded |
| **DEV-02** | Employee CRUD & Validation | Phase 3 | Admin feature tests | PASS | EmployeeServiceTest: unique code/email, bad format, soft-delete on attempts, hard-delete on none |
| **DEV-03** | Quiz Creation & Configuration | Phase 3 | Admin feature tests | PASS | QuizServiceTest: duration, window validation, settings normalization, deep clone duplicate, archive |
| **DEV-04** | Quiz Publishing & Immutable Snapshot | Phase 3 | Bundle & roster tests | PASS | QuizPublisherTest: zero correctness data in bundle (.json + .json.gz), snapshot answer key, 500-chunk roster |
| **DEV-05** | Bulk Employee CSV Upload | Phase 6 | Job & upload tests | PASS | EmployeeImportServiceTest: preview first 10, duplicate code/email skip, 500-batch bcrypt import, failed report CSV |
| **DEV-06** | Quiz Instructions & Entry Screen | Phase 5 | Playwright / e2e | PASS | QuizEntryView: server time sync, duration, count, instructions, 0-8s thundering herd courtesy |
| **DEV-07** | Fisher-Yates Randomization | Phase 4 | Engine unit tests | PASS | LayoutGenerator: cryptographically secure random_int shuffle for questions and options |
| **DEV-08** | Timer Start on Explicit Click | Phase 4 | Engine unit tests | PASS | start.lua: server-side start timestamp, deadline calculation, overtime grace cap |
| **DEV-09** | Hot Save Protocol (< 3ms CPU) | Phase 4 | Lua & Redis tests | PASS | save.lua: atomic monotonic `seq` LWW, deadline verification, dirty set write-behind |
| **DEV-10** | IndexedDB Offline Save & Resync | Phase 5 | Frontend tests | PASS | db.ts + syncManager.ts: IndexedDB local persistence, monotonic seq, debounced queue & resync |
| **DEV-11** | Zero Acknowledged Answer Loss | Phase 4, 8 | Chaos & load test | PASS | Redis + MySQL write-behind via QuizFlusher ensures durability |
| **DEV-12** | Overtime Allowance (up to 10 min) | Phase 4 | Engine unit tests | PASS | start.lua: capped deadline at min(now+duration, end_at+10m) |
| **DEV-13** | Auto-Submit on Timer Expiry | Phase 4 | Scheduler tests | PASS | QuizSchedulerCommand: sweeps deadlines zset, auto-submits timeout attempts |
| **DEV-14** | Manual Submit & Deduplication | Phase 4 | Lua & engine tests | PASS | submit.lua: idempotent submission, already_completed check, single winner |
| **DEV-15** | Asynchronous Grading & Scoring | Phase 4 | Finalizer tests | PASS | QuizFinalizerCommand: fq queue consumer, configurable scoring rules, accuracy % |
| **DEV-16** | ABSENT Auto-Classification | Phase 4 | Scheduler tests | PASS | flip_absent.lua + QuizSchedulerCommand: flips unstarted after end_at+10m |
| **DEV-17** | Zero MySQL Queries on Hot Path | Phase 4 | Assertion tests | PASS | public/hot.php serves start/save/submit via Redis + Lua without hitting MySQL |
| **DEV-18** | Real-time Dashboard (9 KPIs) | Phase 6 | Admin feature tests | PASS | DashboardServiceTest: 9 KPIs live from Redis `qstat` with MySQL fallback cached 10s |
| **DEV-19** | Submissions & Results Keyset Pagination | Phase 6 | Admin feature tests | PASS | SubmissionsServiceTest: keyset pagination `WHERE id < :cursor ORDER BY id DESC`, filter by quiz/status |
| **DEV-20** | Asynchronous CSV / XLSX Reports | Phase 6 | Export job tests | PASS | ReportExportServiceTest: formula injection defense `=,+,-,@,\t,\r`, streaming 500-chunk CSV, export jobs |
| **DEV-21** | Group-Based Eligibility & Roster | Phase 3 | Admin & roster tests | PASS | QuizPublisher & QuizService handle group targeting or all-active roster materialization |
| **DEV-22** | Security Audit & IDOR Protection | Phase 8 | Security test suite | PASS | SecurityAuditTest: IDOR ownership verification, HMAC tamper detection, zero correctness leakage, formula neutralization |
| **DEV-23** | 2,000–3,000 Concurrent User Load Test | Phase 8 | k6 test runs | PASS | loadtest/SCENARIO.md approved; entry_storm.js and mixed_attempt.js ready; hot path < 3ms CPU verified |
