# Acceptance Criteria Verification Matrix (DEV-01 to DEV-23)

This document tracks the acceptance criteria defined in §39 of the Client Specification across all implementation phases.

| ID | Description / Area | Phase | Target Verification | Status | Evidence / Notes |
|---|---|---|---|---|---|
| **DEV-01** | Employee Auth & Pre-Window Access | Phase 2, 5 | Unit tests & SPA flow | PASS (Backend) | AuthProvider bcrypt + token verification + role escalation blocked + 2,500 seeded |
| **DEV-02** | Employee CRUD & Validation | Phase 3 | Admin feature tests | PASS | EmployeeServiceTest: unique code/email, bad format, soft-delete on attempts, hard-delete on none |
| **DEV-03** | Quiz Creation & Configuration | Phase 3 | Admin feature tests | PASS | QuizServiceTest: duration, window validation, settings normalization, deep clone duplicate, archive |
| **DEV-04** | Quiz Publishing & Immutable Snapshot | Phase 3 | Bundle & roster tests | PASS | QuizPublisherTest: zero correctness data in bundle (.json + .json.gz), snapshot answer key, 500-chunk roster |
| **DEV-05** | Bulk Employee CSV Upload | Phase 6 | Job & upload tests | PENDING | Preview, skip duplicates, failed CSV |
| **DEV-06** | Quiz Instructions & Entry Screen | Phase 5 | Playwright / e2e | PENDING | Sync server time, duration, count |
| **DEV-07** | Fisher-Yates Randomization | Phase 4 | Engine unit tests | PENDING | Per-attempt shuffled layout, key decoupled |
| **DEV-08** | Timer Start on Explicit Click | Phase 4 | Engine unit tests | PENDING | Server start timestamp, deadline calculation |
| **DEV-09** | Hot Save Protocol (< 3ms CPU) | Phase 4 | Lua & Redis tests | PENDING | Atomic SAVE Lua, monotonic `seq` LWW |
| **DEV-10** | IndexedDB Offline Save & Resync | Phase 5 | Frontend tests | PENDING | Queue in browser, resync on `max_seq` mismatch |
| **DEV-11** | Zero Acknowledged Answer Loss | Phase 4, 8 | Chaos & load test | PENDING | Redis restart mid-quiz resync |
| **DEV-12** | Overtime Allowance (up to 10 min) | Phase 4 | Engine unit tests | PENDING | Capped deadline at `end_at + 10m` |
| **DEV-13** | Auto-Submit on Timer Expiry | Phase 4 | Scheduler tests | PENDING | Expirer batch sweep from `deadlines` zset |
| **DEV-14** | Manual Submit & Deduplication | Phase 4 | Lua & engine tests | PENDING | SUBMIT Lua idempotency, single winner |
| **DEV-15** | Asynchronous Grading & Scoring | Phase 4 | Finalizer tests | PENDING | `fq` consumer, configurable scoring |
| **DEV-16** | ABSENT Auto-Classification | Phase 4 | Scheduler tests | PENDING | Closer flips unstarted after `end_at + 10m` |
| **DEV-17** | Zero MySQL Queries on Hot Path | Phase 4 | Assertion tests | PENDING | `Threads_connected` static during hot calls |
| **DEV-18** | Real-time Dashboard (9 KPIs) | Phase 6 | Admin feature tests | PENDING | Live pull from Redis `qstat` |
| **DEV-19** | Submissions & Results Keyset Pagination | Phase 6 | Admin feature tests | PENDING | Filter by quiz/status, no heavy offsets |
| **DEV-20** | Asynchronous CSV / XLSX Reports | Phase 6 | Export job tests | PENDING | Streaming OpenSpout, formula injection safe |
| **DEV-21** | Group-Based Eligibility & Roster | Phase 3 | Admin & roster tests | PASS | QuizPublisher & QuizService handle group targeting or all-active roster materialization |
| **DEV-22** | Security Audit & IDOR Protection | Phase 8 | Security test suite | PENDING | Ownership verification, token tamper check |
| **DEV-23** | 2,000–3,000 Concurrent User Load Test | Phase 8 | k6 test runs | PENDING | Pass criteria in `docs/LOADTEST.md` |
