# CorpQuiz Observability & Telemetry Architecture

This document defines the observability design, telemetry endpoints, alert rules, and operational monitoring workflows for the **CorpQuiz Employee Quiz Management System** deployed on Hostinger KVM 2 (2 vCPU, 8 GB RAM, Ubuntu LTS).

---

## 1. Architectural Principles

Given the strict 2 vCPU budget and the load target of **2,000+ concurrent employees**, observability must follow these constraints:
1. **Zero Impact on Hot Path:** The hot endpoints (`/api/quiz/*`, `/api/attempts/*`, `/api/time/*`) perform zero extra I/O or logging for metrics.
2. **Lightweight Pull-Based Scraping:** Exporters pull state asynchronously. No heavy tracing daemons (e.g., full Java APMs) are run on the application server.
3. **Sub-second Lag Detection:** High-frequency scrape intervals (5s) for Redis queue lengths (`dirty_att`, `fq`) to catch write-behind and grading bottlenecks immediately during storm windows.

---

## 2. Telemetry Endpoints & Tools

| Component | Endpoint / Command | Protocol / Format | Purpose |
|---|---|---|---|
| **CorpQuiz Metrics** | `http://127.0.0.1/metrics` | Prometheus Exposition (v0.0.4) | Exposes queue depths, worker heartbeats, attempt states, and database stats. |
| **CLI Metrics Dump** | `php bin/console quiz:metrics [--json]` | Prometheus / JSON | Ad-hoc CLI inspection of app metrics. |
| **Live Terminal Monitor** | `php bin/console quiz:monitor` | Interactive ANSI CLI Dashboard | Real-time 1s refreshed status dashboard during quiz storms. |
| **Host Metrics** | `http://127.0.0.1:9100/metrics` | Prometheus (Node Exporter) | CPU, RAM, Disk I/O, Network, somaxconn. |
| **Nginx Ingress** | `http://127.0.0.1/stub_status` | Text (stub_status) | Active connections, request rates. |
| **PHP-FPM Pools** | `http://127.0.0.1/status-{hot,auth,main}` | FPM Status | Active children, listen queue length, max children reached. |

---

## 3. Metrics Catalogue

### Worker Liveness & Heartbeats
- `quiz_worker_status{worker="flusher|finalizer|scheduler|jobs"}`: Gauge (1 = healthy/running, 0 = down/hung).
- `quiz_worker_heartbeat_age_seconds{worker="..."}`: Seconds elapsed since the worker completed its last loop pass.
- `quiz_worker_heartbeat_timestamp{worker="..."}`: Unix timestamp of the last recorded heartbeat.

### Queue Depths & Write-Behind Lags
- `quiz_queue_dirty_attempts`: Count of unwritten attempts in Redis `dirty_att` set waiting for MySQL batch update.
- `quiz_queue_dirty_answers`: Count of answers in Redis `dirty` set waiting for MySQL batch insertion.
- `quiz_queue_finalizer_length`: Count of submitted attempts in Redis `fq` list waiting for grading.
- `quiz_queue_dead_letter_length`: Count of failed records in `dlq`.
- `quiz_warm_quizzes_count`: Count of active quizzes pre-warmed in Redis cache.

### Redis & Storage Performance
- `quiz_redis_up`: Gauge (1 = connected, 0 = down).
- `quiz_redis_used_memory_bytes`: Memory allocated by Redis (cap is 1 GB).
- `quiz_redis_connected_clients`: Active client connections to Redis.
- `quiz_redis_ops_per_sec`: Instantaneous Redis operations per second.
- `quiz_redis_hit_rate_ratio`: Cache hit ratio.

### MySQL Database Health
- `quiz_db_up`: Gauge (1 = connected, 0 = down).
- `quiz_attempts_total{status="NOT_STARTED|IN_PROGRESS|COMPLETED|EXPIRED|ABSENT"}`: Current distribution of attempt statuses.
- `quiz_mysql_threads_connected`: Active connections to MySQL.
- `quiz_mysql_threads_running`: Threads actively executing queries.
- `quiz_mysql_slow_queries_total`: Cumulative count of queries exceeding 0.5s.

---

## 4. Alerting Rules

Configured in `ops/observability/prometheus/alert.rules.yml`:

| Alert Name | Condition | Severity | Action / Mitigation |
|---|---|---|---|
| **`CorpQuizWorkerDown`** | Worker heartbeat age > 20s for 30s | **CRITICAL** | Restart daemon: `systemctl restart quiz-<worker>` |
| **`CorpQuizDirtyAttemptsLag`** | `quiz_queue_dirty_attempts > 500` for 1m | **WARNING** | Check MySQL slow log; run manual flush pass `php bin/console quiz:flusher --once` |
| **`CorpQuizFinalizerQueueLag`** | `quiz_queue_finalizer_length > 200` for 1m | **CRITICAL** | Spawn auxiliary finalizer: `php bin/console quiz:finalizer >> /var/log/corpquiz/finalizer.log 2>&1 &` |
| **`CorpQuizDeadLetterQueueNotEmpty`** | `quiz_queue_dead_letter_length > 0` for 1m | **CRITICAL** | Inspect `/var/log/corpquiz/finalizer_error.log` and DLQ entries |
| **`CorpQuizRedisDown`** | `quiz_redis_up == 0` for 15s | **CRITICAL** | Restart Redis: `systemctl restart redis-server`; re-warm quizzes |
| **`CorpQuizRedisMemoryHigh`** | Used memory > 850 MB for 1m | **CRITICAL** | Clear stale caches or increase memory cap |
| **`HostHighCpuUsage`** | Sustained CPU > 85% for 2m | **CRITICAL** | Check top processes (`htop`); protect 2 vCPU budget |
| **`HostLowDiskSpace`** | Free NVMe disk < 15 GB for 2m | **WARNING** | Clean `/var/log/corpquiz` or old backups |
| **`PhpFpmPoolStarvation`** | Pool `max_children_reached > 0` or listen queue > 0 | **CRITICAL** | Check pool sizing in `/etc/php/8.2/fpm/pool.d/` |
| **`Http5xxErrorRateHigh`** | 5xx error rate > 0.1% for 1m | **CRITICAL** | Inspect `/var/log/nginx/error.log` and PHP-FPM error logs |

---

## 5. Deployment & Provisioning

### 1. Automated VPS Provisioning
Run the setup script on the Ubuntu server:

* **Option 1: Full Local Prometheus (Self-contained on VPS)**
  ```bash
  sudo bash /var/www/corpquiz/ops/observability/setup-observability.sh --local
  ```

* **Option 2: Remote Scraping / Grafana Cloud (Zero TSDB overhead on 2 vCPU VPS)**
  ```bash
  sudo bash /var/www/corpquiz/ops/observability/setup-observability.sh --remote
  ```
  *(Or run without arguments for an interactive prompt).*

### 2. Live CLI Monitoring on Assessment Day
Connect to the server via SSH and launch the live monitor:
```bash
php /var/www/corpquiz/bin/console quiz:monitor
```

### 3. Grafana Dashboard Setup
1. In Grafana or **Grafana Cloud** (Free Tier recommended to offload VPS compute):
2. Navigate to **Dashboards** → **New** → **Import**.
3. Upload `ops/observability/grafana/corpquiz-dashboard.json`.
4. Select your Prometheus data source and click **Import**.
