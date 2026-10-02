# Load Testing Specification & Acceptance Criteria (DEV-23)

## 1. Executive Summary
- **Target System Capacity:** 2,000 concurrent employees taking an assessment simultaneously.
- **Safety Headroom Target:** $1.5\times$ target = 3,000 concurrent employees.
- **Infrastructure Target:** Hostinger KVM 2 VPS (2 vCPU, 8 GB RAM, NVMe SSD, Ubuntu LTS, India region, Cloudflare proxy).
- **Core Architecture:** Zero-framework, zero-MySQL hot path (`hot.php`) backed by Redis Unix Domain Socket and atomic Lua scripts.

---

## 2. Load Scenarios

### Scenario 1: Entry Storm (2,000 & 3,000 Users)
- **Profile:** 2,000 employees authenticate (`POST /api/auth/login`) and fetch quiz metadata (`GET /api/quiz/{code}`) over 120 seconds.
- **Harsh Variant:** 2,000 authentications compressed into 20 seconds.
- **Objectives:**
  - Verify Nginx `limit_req` allows legitimate logins without dropping active employees.
  - Verify auth pool isolates bcrypt CPU workload from hot path.
  - Verify ETag 304 Not Modified caching on quiz metadata.

### Scenario 2: Start Burst (Peak $\ge 100$ starts/sec)
- **Profile:** 2,000 users click "Start Quiz" within a 20-second window. Peak start rate $\ge 100$ requests/sec.
- **Objectives:**
  - Verify atomic Fisher-Yates question and option randomization in `start.lua`.
  - Verify deadline calculation applies overtime grace cap $\min(\text{now} + \text{duration}, \text{end\_at} + 600\text{s})$.
  - Verify double-click idempotency (duplicate clicks return existing attempt state without creating new attempts).

### Scenario 3: Mixed Attempt Soak (30 Minutes)
- **Profile:** 2,000 active concurrent sessions over 30 minutes:
  - 40 questions per assessment.
  - Users answer questions and save (`PUT /api/attempts/{aid}/answers`) every 15–45 seconds with random jitter.
  - 5% random disconnect/refresh simulation (testing IndexedDB resumption and `max_seq` re-sync).
- **Objectives:**
  - Measure hot save CPU time ($\le 3$ ms per request).
  - Verify zero acknowledged answer loss.
  - Verify Redis-to-MySQL flusher write-behind does not cause MySQL lock contention or replication lag.

### Scenario 4: Submit & Expiry Storm
- **Profile:**
  - In the final 3 minutes of the assessment window, 60% of users trigger manual submissions (`POST /api/attempts/{aid}/submit`) with double-click attempts.
  - Remaining 40% of users leave timers to expire, testing `quiz:scheduler` auto-submission.
- **Objectives:**
  - Verify SUBMIT Lua idempotency: exactly one winner per attempt, zero duplicate completions.
  - Verify `quiz:finalizer` asynchronous grading drains queue within 60 seconds of window close.

### Scenario 5: Results Storm
- **Profile:** 2,000 candidates query their attempt score and breakdown (`GET /api/attempts/{aid}`) within 60 seconds of completion.
- **Objectives:**
  - Verify fast read from Redis hash `att:{aid}`.
  - Verify 100% accurate score and accuracy matching the answer key.

### Scenario 6: Admin Interference (Concurrent with 3–5)
- **Profile:**
  - 5 admin sessions continuously refreshing live dashboard (`/admin/dashboard`) every 5 seconds.
  - One 20,000-row bulk employee CSV import in progress.
  - One full submissions CSV export job running in background.
- **Objectives:**
  - Verify dashboard queries pull from Redis `qstat` or cached aggregates.
  - Verify admin requests do not degrade candidate hot path latency.

### Scenario 7: Chaos & Resilience
- **Chaos Injections:**
  1. Restart Redis server mid-assessment.
  2. Pause MySQL for 60 seconds.
  3. Terminate `quiz:flusher` and `quiz:finalizer` worker processes.
- **Objectives:**
  - Verify client IndexedDB auto-heals and flushes pending answers once Redis recovers.
  - Verify flusher reconciler catches unwritten attempts without data loss.

---

## 3. Strict Pass Criteria

| Metric | Target (2,000 Users) | Headroom (3,000 Users) |
|---|---|---|
| **Acknowledged Answer Loss** | **0 lost answers** | **0 lost answers** |
| **Duplicate Submissions** | **0 duplicates** | **0 duplicates** |
| **Hot Save Latency (p99)** | **< 300 ms** | **< 500 ms** |
| **Start Burst Latency (p99)** | **< 500 ms** | **< 800 ms** |
| **Submit Latency (p99)** | **< 500 ms** | **< 800 ms** |
| **Overall HTTP Error Rate** | **< 0.1%** | **< 0.5%** |
| **Grading Lag** | **< 60 seconds** | **< 90 seconds** |
| **Admin Dashboard (p95)** | **< 1.0 second** | **< 1.5 seconds** |
