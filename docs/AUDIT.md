# Framework Audit: dinzin-tech/simple-mvc

This document provides a technical audit of the in-house framework `dinzin-tech/simple-mvc` (and skeleton `simple-mvc-app`) against the requirements of the Employee Quiz Management System (2,000+ concurrent users on a 2 vCPU VPS).

---

## Audit Checklist & Findings

### 1. Routing
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/Router.php`
  - `vendor/dinzin-tech/simple-mvc/src/Kernel.php`
  - `vendor/dinzin-tech/simple-mvc/src/MiddlewareResolver.php`
- **Findings:**
  - `Router::scanControllers()` scans `app/controllers` recursively, requires each `.php` file, inspects `get_declared_classes()`, and uses `ReflectionClass` to parse docblock `@Route(...)` annotations on every request if `DEBUG_MODE === 'true'` or if `cache/routes.php` is missing.
  - Route cache exists: `bin/console route:cache` generates `cache/routes.php`. When present and `DEBUG_MODE !== 'true'`, `Router::loadRoutes()` loads this file.
  - `Router::dispatch()` performs a linear loop over all routes, running `preg_replace` and `preg_match` per route.
  - On every matched route, `Router::dispatch()` instantiates `new \Core\MiddlewareResolver(BASE_PATH . '/config/middlewares.yml')` which parses `config/middlewares.yml` using `\Symfony\Component\Yaml\Yaml::parseFile` on every request.
  - **Bootstrap Benchmark:**
    - Cold bootstrap (including `Dotenv::load`, `Kernel::boot`, `Session::start`, filesystem route inspection): **~30.8 ms** of CPU time.
    - Subsequent warm bootstraps within the same CLI process: ~3.1–4.0 ms.
    - *Critical Impact:* On a 2 vCPU VPS, 30 ms of CPU per request caps total server capacity at ~66 requests/second before 100% CPU saturation.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1):**
  - Hot path (`/api/quiz/*`, `/api/attempts/*`, `/api/time`) completely bypasses `Router.php` and framework `Kernel` via `public/hot.php`.
  - Implement a lightweight, ~30-line regex dispatcher (`app/Hot/HotRouter.php`) in Phase 1 with < 0.2 ms CPU overhead.
  - Main/Admin routes use `route:cache` and middleware cache in production.

---

### 2. Database Layer
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/Database.php`
  - `vendor/dinzin-tech/simple-mvc/src/QueryBuilder.php`
- **Findings:**
  - **Connection:** Lazy connection via `Database::getInstance($name)`. Only connects to MySQL when a query is executed.
  - **PDO Configuration:** `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`, `PDO::ATTR_EMULATE_PREPARES => false`, `charset=utf8mb4`.
  - **Parameter Binding:** `where()`, `whereIn()`, `insert()`, and `update()` use parameterized PDO bindings.
  - **LIMIT / OFFSET:** Cast to `int` and string-interpolated (`LIMIT {$this->limit} OFFSET {$this->offset}`). Safe from injection due to type constraints, but not PDO-bound.
  - **ORDER BY:** **Raw string interpolation!** `$this->orderBys[] = "$column " . strtoupper($direction)`. If `$column` is received from request parameters without an explicit whitelist, it introduces SQL injection vulnerability.
  - **Transactions:** `Database.php` has no transaction wrappers (`beginTransaction`, `commit`, `rollBack`), though `$db->getConnection()` returns raw `PDO`. Crucially, `Database::query()` catches `PDOException` internally, logs it, and returns `false`, which suppresses transaction aborts unless raw PDO is used.
  - **Multi-Row Insert:** **Not supported** by `QueryBuilder::insert()`. Only accepts a single 1D associative array.
  - **INSERT ... ON DUPLICATE KEY UPDATE:** **Not supported** by `QueryBuilder`. Must be executed via `raw()` or prepared statements on the raw PDO connection.
  - **Fatal on Connection Failure:** `Database::__construct()` catches `PDOException` and invokes `die('Database connection error...')`. In worker daemons, this kills the worker process abruptly.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1, Phase 2 & Phase 4):**
  - Hot path has **zero MySQL queries**; Redis is the system of record for active attempt state.
  - For write-behind persistence (`quiz:flusher`, `quiz:finalizer`), write custom multi-row batch insert/update helpers using prepared PDO statements with chunked transactions (500–1000 rows).
  - Admin query handlers must strictly whitelist all `ORDER BY` columns.

---

### 3. Request / Response
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/http/Request.php`
  - `vendor/dinzin-tech/simple-mvc/src/http/Response.php`
- **Findings:**
  - `Request.php` only reads `$_POST` and `$_GET`. It does **not** read or parse JSON request bodies from `php://input`.
  - `Request.php` has no methods for reading request headers (`Authorization`, `If-None-Match`, `X-Server-Time`).
  - `Response.php` provides `$response->json($data, $statusCode)`, but lacks streaming responses and conditional request headers (`ETag`, `Last-Modified`).
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1):**
  - Hot path implements a lightweight JSON request parser directly reading `php://input` with depth and body limits (≤ 64 KB), returning structured JSON errors `{"error":{"code","message"}}`.
  - Static bundles use Nginx `X-Accel-Redirect` / `sendfile` to bypass PHP entirely for file streaming.

---

### 4. Middleware Pipeline
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/MiddlewareHandler.php`
  - `vendor/dinzin-tech/simple-mvc/src/MiddlewareResolver.php`
- **Findings:**
  - `MiddlewareResolver` reads and parses `config/middlewares.yml` using Symfony YAML on every route resolution.
  - Middleware execution uses a recursive closure runner. If a middleware is not found, returns a 500 Response.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1 & Phase 3):**
  - Hot path executes zero framework middleware. Rate limiting (Redis `INCR`+TTL) and token HMAC validation are executed as pure PHP functions in `public/hot.php`.
  - Admin/Main pipeline caches parsed middleware configurations.

---

### 5. Cache Driver
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/Cache.php`
- **Findings:**
  - Hardcoded to phpfastcache `Files` driver in `storage/cache`.
  - Hardcoded security key `my_secure_key`.
  - Cannot use Redis without altering framework code. File-based caching under 2,000 concurrent users causes severe disk I/O bottlenecks and lock contention.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1 & Phase 4):**
  - Do not use `Core\Cache`.
  - Hot path uses native `ext-redis` (`\Redis` over unix socket) for dynamic attempt state and `ext-apcu` (`apcu_fetch`/`apcu_store`) for immutable quiz metadata and structure validation.

---

### 6. Logging & Error Handling
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/Logger.php`
  - `vendor/dinzin-tech/simple-mvc/src/Kernel.php`
  - `vendor/dinzin-tech/simple-mvc/src/Debug.php`
- **Findings:**
  - `Kernel::terminate()` unconditionally calls `Logger::accessLog($request, $response)` on **EVERY** request, executing `file_put_contents(BASE_PATH . '/storage/logs/access.log', ...)` without file locks (`LOCK_EX`).
  - Writing access logs to disk in PHP under hundreds of requests/sec degrades NVMe throughput and risks file corruption.
  - No log rotation mechanism is built in.
  - `Debug.php` attaches custom error and exception handlers; if `DEBUG_MODE=true`, it renders a heavy debug toolbar.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1 & Phase 7):**
  - Hot path performs **no per-request logging** in PHP.
  - Access logging is delegated to Nginx (buffered, or disabled on high-frequency save endpoints `/api/attempts/*/answers`).
  - Production environment enforces `DEBUG_MODE=false`, `display_errors=0`, and logs only errors via system logrotate.

---

### 7. Statics and Singletons (Worker Safety)
- **File Paths:**
  - `vendor/dinzin-tech/simple-mvc/src/Database.php`
  - `vendor/dinzin-tech/simple-mvc/src/Kernel.php`
  - `vendor/dinzin-tech/simple-mvc/src/Session.php`
- **Findings:**
  - `Database::$queries` stores every executed SQL query, binding, and execution duration in a static PHP array. In a long-running CLI worker (`quiz:flusher`, `quiz:finalizer`), this array grows unbounded, causing memory leaks (`Allowed memory size exhausted`).
  - `Kernel::boot()` invokes `Session::start()` on every request, initializing standard PHP disk session files. This causes concurrent session file lock contention.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1 & Phase 4):**
  - Hot path never initializes PHP sessions; authentication is strictly stateless signed tokens.
  - Background workers reset static query buffers or use dedicated PDO instances directly.

---

### 8. Console Commands
- **File Paths:**
  - `bin/console`
  - `vendor/dinzin-tech/simple-mvc/src/console/CommandManager.php`
- **Findings:**
  - `CommandManager` scans `app/Commands` and registers classes implementing `execute(array $args)`.
  - Command names are derived from class names by stripping `Command` and lowercasing.
  - No signal handling (`pcntl_signal`) or process management is provided.
- **Status:** **OK (with Worker-level Signal Handling)**
- **Remediation (Assigned to Phase 4):**
  - Long-running worker commands (`quiz:scheduler`, `quiz:flusher`, `quiz:finalizer`, `jobs:worker`) will implement custom `pcntl_signal(SIGTERM, ...)` and `pcntl_signal(SIGINT, ...)` handlers for graceful shutdown and batch draining.

---

### 9. Composer Test & Lint
- **Findings:**
  - `composer test` runs `phpunit` and passes (7 tests, 7 assertions, 0 failures).
  - `composer lint` initially failed because `squizlabs/php_codesniffer` was missing from `composer.json` and `commands/` directory did not exist.
  - Resolved in Phase 0: Added `squizlabs/php_codesniffer` to `require-dev`, created `phpcs.xml` (PSR-12 ruleset with tests pattern exclusion), formatted code with `phpcbf`, and verified `composer lint` passes cleanly (0 errors, 0 warnings).
- **Status:** **OK (Resolved in Phase 0)**

---

### 10. Environment & Secret Management
- **File Paths:**
  - `.env`, `.env.example`, `.gitignore`
  - `vendor/dinzin-tech/simple-mvc/src/JWT.php`
- **Findings:**
  - `.env` is properly ignored in `.gitignore`.
  - `JWT.php` uses `$_ENV['APP_SECRET']` with `hash_hmac('sha256', ...)`.
  - `JWT::decode()` uses `explode('.', $jwt)` without length checks, causing PHP notices/fatal on malformed tokens. It also lacks `kid` (key identifier) support for secret rotation.
- **Status:** **NEEDS WORK**
- **Remediation (Assigned to Phase 1):**
  - Implement `app/Hot/Token.php` supporting compact `payload.hmac_sha256` tokens with claims `{uid, role, exp, kid}`, constant-time `hash_equals`, and malformed token rejection.
  - Compile secrets and configuration into `config/hot.php` via `bin/console config:cache`. Hot paths will never parse `.env` at runtime.

---

## Audit Summary & Action Items

| Item | Component | Status | Target Phase |
|---|---|---|---|
| 1 | Controller scan & Reflection in Router | **NEEDS WORK** | Phase 1 (HotRouter) |
| 2 | SQL Injection in `orderBy`, Missing Bulk Inserts | **NEEDS WORK** | Phase 2, 4 (PDO Bulk Helpers) |
| 3 | JSON input parsing & header inspection missing | **NEEDS WORK** | Phase 1 (Hot Request Helpers) |
| 4 | Runtime YAML parsing in MiddlewareResolver | **NEEDS WORK** | Phase 1 (Bypass on Hot Path) |
| 5 | Hardcoded File Cache in Core\Cache | **NEEDS WORK** | Phase 1, 4 (Redis + APCu) |
| 6 | Unbuffered PHP file access logging | **NEEDS WORK** | Phase 1, 7 (Nginx / Zero PHP log) |
| 7 | Unbounded `Database::$queries` memory leak | **NEEDS WORK** | Phase 4 (Worker memory management) |
| 8 | Console command execution & signals | **OK** | Phase 4 (Worker daemon loops) |
| 9 | Missing `phpcs` in `require-dev` | **NEEDS WORK** | Phase 0 / Phase 1 (`composer.json`) |
| 10 | Dotenv parsing overhead & JWT robustness | **NEEDS WORK** | Phase 1 (`config:cache` & `Token`) |
