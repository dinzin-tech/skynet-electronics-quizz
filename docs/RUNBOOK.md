# Operational Runbook: Employee Quiz Management System

This runbook defines the standard operating procedures, assessment-day checklists, failure mitigations, and backup/recovery instructions for the CorpQuiz application running on Hostinger KVM 2 (2 vCPU, 8 GB RAM, Ubuntu LTS).

---

## 1. Assessment Day Checklist

### T-60 Minutes: Infrastructure Health Audit
1. **CPU & Memory Check:**
   ```bash
   free -h
   uptime
   ```
   Ensure at least 4 GB free RAM is available.

2. **Disk Space Check:**
   ```bash
   df -h /
   ```
   Ensure at least 15 GB free NVMe disk space is available for logs, Redis AOF, and database writes.

3. **Background Daemons Status:**
   ```bash
   systemctl status quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs
   ```
   All four services must be in `active (running)` state.

4. **Redis Connectivity & Memory:**
   ```bash
   redis-cli -s /run/redis/redis-server.sock info memory
   ```
   Verify `used_memory` is well below the 1 GB cap.

---

### T-15 Minutes: Pre-Warming Verification
1. Ensure the target quiz has been published in Admin Console.
2. Trigger pre-warming (or verify automatic scheduler pre-warming):
   ```bash
   php bin/console quiz:warm <quiz_id>
   ```
3. Inspect warmed keys in Redis:
   ```bash
   redis-cli -s /run/redis/redis-server.sock exists quiz:<quiz_code>
   redis-cli -s /run/redis/redis-server.sock exists key:<quiz_id>:<version>
   redis-cli -s /run/redis/redis-server.sock exists qstat:<quiz_id>
   redis-cli -s /run/redis/redis-server.sock scard warm_quizzes
   ```
4. Confirm pre-compressed bundles exist:
   ```bash
   ls -lh /var/www/corpquiz/storage/bundles/
   ```

---

### T-0 to T+Window: Live Monitoring
1. **Live Terminal Monitor (Recommended):**
   ```bash
   php /var/www/corpquiz/bin/console quiz:monitor
   ```
   Provides a real-time (1s refresh) colorized status of all 4 workers, queue depths (`dirty_att`, `dirty`, `fq`, `dlq`), Redis memory/ops, and MySQL threads.

2. **Grafana Dashboard:**
   Open the imported `CorpQuiz - Production Observability Dashboard` (refresh 5s).

3. **Prometheus / App Metrics Endpoint:**
   ```bash
   curl -s http://127.0.0.1/metrics | grep -E "^quiz_"
   ```

4. Monitor Redis operation latency:
   ```bash
   redis-cli -s /run/redis/redis-server.sock --latency
   ```
   Expected latency: $\le 0.5$ ms.

5. Monitor worker queues directly:
   ```bash
   # Pending writes to MySQL
   redis-cli -s /run/redis/redis-server.sock scard dirty_att
   # Pending grading jobs
   redis-cli -s /run/redis/redis-server.sock llen fq
   ```

---

## 2. Emergency Mitigation Procedures

### Scenario A: Finalizer Queue (`fq`) Lagging
**Symptom:** `llen fq` exceeds 500 items; submissions taking $> 15$ seconds to show graded scores.
**Mitigation:**
1. Check finalizer logs:
   ```bash
   tail -n 100 /var/log/corpquiz/finalizer_error.log
   ```
2. Spawn an auxiliary finalizer instance:
   ```bash
   php bin/console quiz:finalizer >> /var/log/corpquiz/finalizer.log 2>&1 &
   ```
3. Once queue is drained, terminate the auxiliary process.

---

### Scenario B: Dirty Attempt Queue (`dirty_att`) Growing
**Symptom:** Redis `dirty_att` contains thousands of unwritten attempts.
**Mitigation:**
1. Check MySQL connection health and slow log:
   ```bash
   tail -n 50 /var/log/mysql/mysql-slow.log
   ```
2. Run a synchronous flush pass:
   ```bash
   php bin/console quiz:flusher --once
   ```

---

### Scenario C: Unexpected Redis Restart Mid-Quiz
**Guarantees:**
- Redis AOF (`appendfsync everysec`) guarantees maximum $\le 1$ second of acknowledged answer loss in a catastrophic crash.
- Client IndexedDB stores all unacknowledged and acknowledged answer sequences locally.
- When clients reconnect, `syncManager.ts` automatically re-submits any local sequences higher than the server's acknowledged `max_seq`.

**Procedure:**
1. Confirm Redis restarted:
   ```bash
   systemctl status redis-server
   ```
2. Re-warm active quizzes:
   ```bash
   php bin/console quiz:warm all
   ```
3. Background workers (`flusher`, `scheduler`, `finalizer`) automatically reconnect with exponential backoff.

---

## 3. Daily Backup & Restore

### Daily Backup Script (Cron: 02:00 UTC)
```bash
#!/usr/bin/env bash
set -e
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/var/backups/corpquiz"
mkdir -p "$BACKUP_DIR"

# 1. MySQL Dump
mysqldump --single-transaction --quick --lock-tables=false \
    -u root -p"$DB_PASS" corp_quizz | gzip > "$BACKUP_DIR/db_$TIMESTAMP.sql.gz"

# 2. Redis RDB snapshot
redis-cli -s /run/redis/redis-server.sock bgsave
while [ $(redis-cli -s /run/redis/redis-server.sock lastsave) -le $PREV_SAVE ]; do sleep 1; done
cp /var/lib/redis/dump.rdb "$BACKUP_DIR/redis_$TIMESTAMP.rdb"

# 3. Clean backups older than 14 days
find "$BACKUP_DIR" -type f -mtime +14 -delete
```

### Restore Procedure
```bash
# 1. Stop background workers
systemctl stop quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs

# 2. Restore MySQL
gunzip < /var/backups/corpquiz/db_YYYYMMDD.sql.gz | mysql -u root -p corp_quizz

# 3. Restart workers
systemctl start quiz-flusher quiz-scheduler quiz-finalizer quiz-jobs
```

---

## 4. Rollback Procedure
If a bad deployment occurs:
1. Revert git commit:
   ```bash
   git reset --hard HEAD~1
   ```
2. Re-run deploy script:
   ```bash
   /var/www/corpquiz/ops/deploy.sh
   ```
3. Verify `/healthz` returns 200 OK.
