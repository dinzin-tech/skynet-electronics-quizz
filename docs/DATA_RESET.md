# Data Reset — Administrator Guide

> **⚠️ All purges are irreversible. Take a verified MySQL backup before executing any scope.**

## Overview

The Data Reset page (`/admin/data-reset`) lets administrators permanently delete data
by *scope*. Each scope removes a coherent set of MySQL rows, Redis keys, and files.

Access is gated by a server-side passcode (`PASS_CODE` in `.env`) and a 10-minute
unlock window that re-locks on logout or after a successful purge.

---

## Prerequisites

1. **Set `PASS_CODE`** in `.env` to a secret string of **at least 8 characters**.  
   Leave it blank to disable the feature entirely (fail-closed — no default).
2. **Take a MySQL backup** (`mysqldump`) before any purge.
3. **Stop background workers** from the Workers page first.  
   Workers that are running while a purge executes may re-create Redis keys or
   write partial data to cleared tables.

---

## Scopes

| Scope | Label | What it removes |
|---|---|---|
| `employees` | Employees and their submissions | `attempt_answers`, `attempts`, `employee_groups`, `employees`, `groups`, `import_jobs`; Redis `att:*`, `ans:*`, `qa:*`, `qstat:*`, `rl:*`, `dash:*` and exact keys `deadlines fq dirty dirty_att`; files in `storage/imports/*` and `storage/reports/import_*` |
| `quizzes` | Quizzes, questions and their submissions | `attempt_answers`, `attempts`, `quiz_snapshots`, `answer_options`, `questions`, `quizzes`; Redis `quiz:*`, `key:*`, `struct:*`, `qa:*`, `qstat:*`, `att:*`, `ans:*`, `dash:*` + exact keys; `storage/bundles/*` and `public/uploads/questions/*` |
| `reports` | Reports and exports | `export_jobs`; NULLifies `import_jobs.report_path`; `storage/exports/*`, `storage/reports/*` |
| `logs` | Logs | Truncates `audit_logs` table; **truncates in-place** (never deletes) `storage/logs/*.log` and `/var/log/corpquiz/*.log` |
| `cache` | Cache only | Redis `dash:*`, `rl:*`; `storage/cache/*`; calls `apcu_clear_cache()` if available |
| `everything` | Factory reset | Union of all scopes above |

### What is NEVER touched

- `administrators` table
- `migrations` table
- Redis keys matching `worker:heartbeat:*` (clearing these would falsely report workers as down)
- Redis keys matching `revoked:*` (clearing these would un-revoke logged-out JWT tokens)
- System logs (nginx, php-fpm, mysql, redis, journald)

---

## Post-purge behaviour after `employees`

After deleting employees, the service re-creates empty zero-valued
`qstat:{quizId}` hashes for every still-published quiz. This prevents the
scheduler from believing quizzes are warm when their rosters are gone.

**Published quizzes will show zero stats until re-published or re-warmed.**

If any quiz `settings.target_audience` references deleted groups, those
references are **not** rewritten — review quiz target_audience settings manually.

---

## Security model

| Layer | Mechanism |
|---|---|
| Admin session | `Session::get('admin_user')` — same check as all admin pages |
| Passcode required | `PASS_CODE` env var, min 8 chars; disabled if unset or too short |
| Passcode check | `hash_equals(sha256(expected), sha256(given))` — timing-safe |
| Passcode never logged | Never echoed, stored, or written to `audit_logs.meta` |
| Throttle | 5 wrong attempts → 15-min lock (Redis counter, session fallback) |
| Unlock window | 10 minutes from correct passcode, bound to admin id; re-locks on purge/logout/"Lock Now" |
| CSRF | Per-session token (`random_bytes(32)`), verified with `hash_equals` on every POST |
| Confirmation phrase | Admin must type e.g. `DELETE EMPLOYEES` exactly |
| Live-attempt guard | Warns when attempts are IN_PROGRESS or windows are open; requires "force" checkbox |
| Single-flight lock | `GET_LOCK('corpquiz_purge', 0)` — refuses concurrent purges |
| Audit trail | Row inserted in `audit_logs` after each purge (written last so it survives a `logs` purge) |
| Cache-Control | `no-store` on all Data Reset pages |

---

## Execution algorithm

1. Acquire MySQL `GET_LOCK('corpquiz_purge', 0)` — refuse if locked.
2. **Redis sweep 1** — SCAN + UNLINK manifest patterns.
3. **MySQL TRUNCATE** — in manifest order, per-table try/catch, post-count verify.
4. **Redis sweep 2** — catches keys re-created by workers during truncation.
5. **Files** — delete files inside whitelisted directories; truncate log files in-place.
6. **APCu** — `apcu_clear_cache()` for `cache` / `everything` scopes.
7. **Post-steps** — re-seed `qstat:` hashes for `employees` scope.
8. **Audit row** — inserted last.
9. Release lock in `finally`.

---

## Recovery

Purges are **irreversible**. Recovery requires restoring from a MySQL backup.

Before every purge:

```bash
mysqldump -u quizz_user -p corp_quizz > backup_$(date +%Y%m%d_%H%M%S).sql
```

Redis state for published quizzes can be re-warmed from the Workers page or by
re-publishing affected quizzes.

---

## Follow-up work

- CSRF protection has been added **only** to the Data Reset feature.  
  Retrofitting the rest of the admin panel is a separate task.

---

## Changelog

| Date | Change |
|---|---|
| 2026-10-07 | Initial implementation — see `docs/ASSUMPTIONS.md` |
