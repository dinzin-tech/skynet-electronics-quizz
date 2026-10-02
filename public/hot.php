<?php

declare(strict_types=1);

// Hot Path Entry Point: zero framework bootstrap, zero sessions, zero MySQL on healthy path
require_once __DIR__ . '/../vendor/autoload.php';

use App\Hot\ApcuCache;
use App\Hot\Clock;
use App\Hot\HotRouter;
use App\Hot\LayoutGenerator;
use App\Hot\Lua;
use App\Hot\RateLimiter;
use App\Hot\Redis as HotRedis;
use App\Hot\Token;

$serverTimeMs = Clock::nowMs();

// Load pre-compiled configuration (never parse .env on the hot path)
$configFile = __DIR__ . '/../config/hot.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code' => 'config_not_cached',
            'message' => 'Hot configuration not cached. Run php bin/console config:cache.',
        ]
    ]);
    exit;
}

$config = require $configFile;

// Connect to Redis via unix socket (or local TCP fallback)
try {
    $redis = HotRedis::getConnection($config);
} catch (\Throwable $e) {
    HotRouter::error(503, 'redis_unavailable', 'Storage service temporarily unavailable', $serverTimeMs);
    exit;
}

/**
 * Extract and verify authentication claims from Authorization header.
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>|null
 */
function getHotAuth(array $config, int $nowMs): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!str_starts_with($header, 'Bearer ')) {
        HotRouter::error(401, 'unauthorized', 'Missing or malformed Authorization header', $nowMs);
        return null;
    }

    $token = substr($header, 7);
    $secret = (string) ($config['app']['key'] ?? '');
    $claims = Token::verify($token, $secret);

    if (!$claims) {
        HotRouter::error(401, 'unauthorized', 'Invalid or expired token', $nowMs);
        return null;
    }

    return $claims;
}

$router = new HotRouter();

// 1. GET /api/time (Client clock offset synchronization)
$router->get('/api/time', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    HotRouter::json(200, [
        'server_now_ms' => $nowMs,
        'iso' => Clock::formatIsoMs($nowMs),
    ], $nowMs);
});

// 2. GET /api/quiz/{code} (Quiz metadata and employee attempt status)
$router->get('/api/quiz/{code}', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $claims = getHotAuth($config, $nowMs);
    if (!$claims) {
        return;
    }

    $code = strtoupper(trim((string) ($params['code'] ?? '')));
    $quizMeta = $redis->hGetAll("quiz:{$code}");

    if (empty($quizMeta) || ($quizMeta['status'] ?? '') !== 'published') {
        HotRouter::error(404, 'quiz_not_found', 'Quiz not found or not published', $nowMs);
        return;
    }

    $quizId = (int) $quizMeta['id'];
    $uid = (string) $claims['uid'];

    // Check eligibility from qa:{quizId}
    $aid = $redis->hGet("qa:{$quizId}", $uid);
    if (!$aid) {
        HotRouter::json(403, [
            'state' => 'ineligible',
            'message' => 'You are not enrolled in this quiz',
            'server_now_ms' => $nowMs,
        ], $nowMs);
        return;
    }

    $startMs = (int) ($quizMeta['start_ms'] ?? 0);
    $endMs = (int) ($quizMeta['end_ms'] ?? 0);
    $attStatus = $redis->hGet("att:{$aid}", 'status') ?: 'NOT_STARTED';

    $settings = json_decode((string) ($quizMeta['settings'] ?? '{}'), true) ?? [];
    $meta = [
        'code' => $quizMeta['code'],
        'title' => $quizMeta['title'],
        'instructions' => $quizMeta['instructions'] ?? '',
        'duration_seconds' => (int) $quizMeta['duration_s'],
        'total_questions' => (int) $quizMeta['total_questions'],
        'opens_at_ms' => $startMs,
        'closes_at_ms' => $endMs,
        'navigation' => $settings['navigation'] ?? [],
    ];

    if ($attStatus === 'COMPLETED') {
        HotRouter::json(200, [
            'state' => 'completed',
            'attempt' => ['id' => $aid, 'status' => 'COMPLETED'],
            'meta' => $meta,
            'server_now_ms' => $nowMs,
        ], $nowMs);
        return;
    }

    if ($attStatus === 'ABSENT') {
        HotRouter::json(200, [
            'state' => 'absent',
            'attempt' => ['id' => $aid, 'status' => 'ABSENT'],
            'meta' => $meta,
            'server_now_ms' => $nowMs,
        ], $nowMs);
        return;
    }

    if ($attStatus === 'IN_PROGRESS') {
        HotRouter::json(200, [
            'state' => 'resumable',
            'attempt' => ['id' => $aid, 'status' => 'IN_PROGRESS'],
            'meta' => $meta,
            'server_now_ms' => $nowMs,
        ], $nowMs);
        return;
    }

    // Attempt is NOT_STARTED: check window
    if ($nowMs < $startMs) {
        HotRouter::json(200, [
            'state' => 'not_open',
            'meta' => $meta,
            'server_now_ms' => $nowMs,
        ], $nowMs);
        return;
    }

    if ($nowMs >= $endMs) {
        HotRouter::json(200, [
            'state' => 'closed',
            'meta' => $meta,
            'server_now_ms' => $nowMs,
        ], $nowMs);
        return;
    }

    HotRouter::json(200, [
        'state' => 'can_start',
        'attempt' => ['id' => $aid, 'status' => 'NOT_STARTED'],
        'meta' => $meta,
        'server_now_ms' => $nowMs,
    ], $nowMs);
});

// 3. POST /api/quiz/{code}/start (Atomic attempt start and deadline assignment)
$router->post('/api/quiz/{code}/start', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $claims = getHotAuth($config, $nowMs);
    if (!$claims) {
        return;
    }

    $uid = (string) $claims['uid'];

    // Rate limit: 5 starts per 10s per user
    $rl = RateLimiter::check($redis, $uid, 'start', 5, 10);
    if (!$rl['allowed']) {
        HotRouter::error(429, 'rate_limited', 'Too many requests', $nowMs, ['Retry-After' => (string) $rl['retry_after']]);
        return;
    }

    $code = strtoupper(trim((string) ($params['code'] ?? '')));
    $quizMeta = $redis->hGetAll("quiz:{$code}");

    if (empty($quizMeta) || ($quizMeta['status'] ?? '') !== 'published') {
        HotRouter::error(404, 'quiz_not_found', 'Quiz not found', $nowMs);
        return;
    }

    $quizId = (int) $quizMeta['id'];
    $aid = $redis->hGet("qa:{$quizId}", $uid);
    if (!$aid) {
        HotRouter::error(403, 'ineligible', 'Not eligible for this quiz', $nowMs);
        return;
    }

    $startMs = (int) ($quizMeta['start_ms'] ?? 0);
    $endMs = (int) ($quizMeta['end_ms'] ?? 0);
    $durationMs = ((int) $quizMeta['duration_s']) * 1000;
    $overtimeGraceMs = 600000; // 10 minutes overtime allowance (§3 clarification #3)

    if ($nowMs < $startMs) {
        HotRouter::error(400, 'quiz_not_open', 'Quiz window has not opened yet', $nowMs);
        return;
    }

    if ($nowMs >= ($endMs + $overtimeGraceMs)) {
        HotRouter::error(400, 'quiz_closed', 'Quiz window has closed', $nowMs);
        return;
    }

    // Generate layout (or use cached structure)
    $version = (int) $quizMeta['version'];
    $structJson = $redis->get("struct:{$quizId}:{$version}");
    $structure = $structJson ? json_decode($structJson, true) : [];

    $settings = json_decode((string) ($quizMeta['settings'] ?? '{}'), true) ?? [];
    $navSettings = $settings['navigation'] ?? [];
    $randomizeQuestions = (bool) ($navSettings['randomize_questions'] ?? true);
    $randomizeOptions = (bool) ($navSettings['randomize_options'] ?? true);

    $layout = LayoutGenerator::generate($structure, $randomizeQuestions, $randomizeOptions);
    $layoutJson = json_encode($layout, JSON_UNESCAPED_SLASHES);

    // Execute atomic START Lua script
    $rawRes = Lua::execute(
        $redis,
        'start',
        [
            "att:{$aid}",
            "quiz:{$code}",
            'deadlines',
            'dirty_att',
            "qstat:{$quizId}",
        ],
        [
            (string) $nowMs,
            (string) $durationMs,
            (string) $endMs,
            (string) $overtimeGraceMs,
            (string) $layoutJson,
            $aid,
        ]
    );

    $res = is_string($rawRes) ? json_decode($rawRes, true) : $rawRes;
    if (isset($res['error'])) {
        HotRouter::error(400, (string) $res['error'], 'Failed to start quiz attempt', $nowMs);
        return;
    }

    // Fetch any saved answers (if re-connecting mid-quiz)
    $savedAnswersRaw = $redis->hGetAll("ans:{$aid}") ?: [];
    $answers = [];
    foreach ($savedAnswersRaw as $qid => $record) {
        $parts = explode('|', $record);
        $answers[$qid] = [
            'selected_option_id' => (int) ($parts[0] ?? 0),
            'seq' => (int) ($parts[1] ?? 0),
        ];
    }

    HotRouter::json(200, [
        'attempt_id' => $aid,
        'status' => $res['status'] ?? 'IN_PROGRESS',
        'started_ms' => (int) ($res['started_ms'] ?? $nowMs),
        'deadline_ms' => (int) ($res['deadline_ms'] ?? ($nowMs + $durationMs)),
        'layout' => is_string($res['layout']) ? json_decode($res['layout'], true) : $res['layout'],
        'bundle_url' => "/api/attempts/{$aid}/bundle",
        'answers' => $answers,
        'server_now_ms' => $nowMs,
    ], $nowMs);
});

// 4. GET /api/attempts/{aid} (Resume / State inspection)
$router->get('/api/attempts/{aid}', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $claims = getHotAuth($config, $nowMs);
    if (!$claims) {
        return;
    }

    $aid = trim((string) ($params['aid'] ?? ''));
    $att = $redis->hGetAll("att:{$aid}");

    // Ownership check: must return 404 (not 403) for other employees' attempts
    if (empty($att) || ((string) $att['eid']) !== ((string) $claims['uid'])) {
        HotRouter::error(404, 'attempt_not_found', 'Attempt not found', $nowMs);
        return;
    }

    $savedAnswersRaw = $redis->hGetAll("ans:{$aid}") ?: [];
    $answers = [];
    foreach ($savedAnswersRaw as $qid => $record) {
        $parts = explode('|', $record);
        $answers[$qid] = [
            'selected_option_id' => (int) ($parts[0] ?? 0),
            'seq' => (int) ($parts[1] ?? 0),
        ];
    }

    $status = $att['status'] ?? 'NOT_STARTED';
    $data = [
        'attempt_id' => $aid,
        'status' => $status,
        'started_ms' => (int) ($att['started_ms'] ?? 0),
        'deadline_ms' => (int) ($att['deadline_ms'] ?? 0),
        'submitted_ms' => (int) ($att['submitted_ms'] ?? 0),
        'max_seq' => (int) ($att['max_seq'] ?? 0),
        'layout' => !empty($att['layout']) ? json_decode($att['layout'], true) : null,
        'answers' => $answers,
        'server_now_ms' => $nowMs,
    ];

    if ($status === 'COMPLETED' && ($att['graded'] ?? '') === '1') {
        $data['result'] = [
            'score' => (float) ($att['score'] ?? 0),
            'correct_count' => (int) ($att['correct'] ?? 0),
            'total_questions' => (int) ($att['total'] ?? 0),
            'accuracy' => (float) ($att['accuracy'] ?? 0),
            'completion_time_s' => (int) ($att['time_s'] ?? 0),
        ];
    }

    HotRouter::json(200, $data, $nowMs);
});

// 5. GET /api/attempts/{aid}/bundle (Static bundle retrieval with ETag / 304)
$router->get('/api/attempts/{aid}/bundle', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $claims = getHotAuth($config, $nowMs);
    if (!$claims) {
        return;
    }

    $aid = trim((string) ($params['aid'] ?? ''));
    $att = $redis->hGetAll("att:{$aid}");

    if (empty($att) || ((string) $att['eid']) !== ((string) $claims['uid'])) {
        HotRouter::error(404, 'attempt_not_found', 'Attempt not found', $nowMs);
        return;
    }

    $quizId = $att['quiz_id'] ?? '';
    $ver = $att['ver'] ?? '1';

    $bundleFiles = glob(dirname(__DIR__) . "/storage/bundles/{$quizId}-v{$ver}-*.json");
    if (empty($bundleFiles) || !file_exists($bundleFiles[0])) {
        HotRouter::error(404, 'bundle_not_found', 'Quiz bundle not found', $nowMs);
        return;
    }

    $bundlePath = $bundleFiles[0];
    $sha256 = hash_file('sha256', $bundlePath);
    $etag = "\"{$sha256}\"";

    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if (trim($ifNoneMatch) === $etag) {
        http_response_code(304);
        header("X-Server-Time: {$nowMs}");
        header("ETag: {$etag}");
        header('Cache-Control: public, max-age=86400, immutable');
        return;
    }

    // Serve bundle
    header('Content-Type: application/json; charset=utf-8');
    header("X-Server-Time: {$nowMs}");
    header("ETag: {$etag}");
    header('Cache-Control: public, max-age=86400, immutable');
    readfile($bundlePath);
});

// 6. PUT /api/attempts/{aid}/answers (Hot save protocol, monotonic seq LWW)
$router->put('/api/attempts/{aid}/answers', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $claims = getHotAuth($config, $nowMs);
    if (!$claims) {
        return;
    }

    $uid = (string) $claims['uid'];

    // Rate limit: 60 saves per 10s per user
    $rl = RateLimiter::check($redis, $uid, 'save', 60, 10);
    if (!$rl['allowed']) {
        HotRouter::error(429, 'rate_limited', 'Too many requests', $nowMs, ['Retry-After' => (string) $rl['retry_after']]);
        return;
    }

    $aid = trim((string) ($params['aid'] ?? ''));
    $att = $redis->hGetAll("att:{$aid}");

    if (empty($att) || ((string) $att['eid']) !== $uid) {
        HotRouter::error(404, 'attempt_not_found', 'Attempt not found', $nowMs);
        return;
    }

    $body = (string) file_get_contents('php://input');
    if ($body === '' || strlen($body) > 16384) {
        HotRouter::error(400, 'invalid_payload', 'Payload too large or empty', $nowMs);
        return;
    }

    $payload = json_decode($body, true);
    if (!isset($payload['items']) || !is_array($payload['items'])) {
        HotRouter::error(400, 'invalid_format', 'Expected JSON object with items array', $nowMs);
        return;
    }

    $itemsJson = json_encode($payload['items'], JSON_UNESCAPED_SLASHES);

    $rawRes = Lua::execute(
        $redis,
        'save',
        [
            "att:{$aid}",
            "ans:{$aid}",
            'dirty',
        ],
        [
            (string) $nowMs,
            '5000', // 5s network grace
            (string) $itemsJson,
            $aid,
        ]
    );

    $res = is_string($rawRes) ? json_decode($rawRes, true) : $rawRes;
    if (isset($res['error'])) {
        $status = ($res['error'] === 'deadline_passed' || $res['error'] === 'not_in_progress') ? 409 : 400;
        HotRouter::error($status, (string) $res['error'], 'Answer save rejected', $nowMs);
        return;
    }

    HotRouter::json(200, [
        'ok' => true,
        'max_seq' => (int) ($res['max_seq'] ?? 0),
        'server_now_ms' => $nowMs,
    ], $nowMs);
});

// 7. POST /api/attempts/{aid}/submit (Atomic submit with deduplication)
$router->post('/api/attempts/{aid}/submit', function (array $params, array $config, \Redis $redis, int $nowMs): void {
    $claims = getHotAuth($config, $nowMs);
    if (!$claims) {
        return;
    }

    $uid = (string) $claims['uid'];
    $aid = trim((string) ($params['aid'] ?? ''));
    $att = $redis->hGetAll("att:{$aid}");

    if (empty($att) || ((string) $att['eid']) !== $uid) {
        HotRouter::error(404, 'attempt_not_found', 'Attempt not found', $nowMs);
        return;
    }

    $body = (string) file_get_contents('php://input');
    $payload = $body !== '' ? json_decode($body, true) : [];
    $reason = (string) ($payload['reason'] ?? 'manual');
    if (!in_array($reason, ['manual', 'timeout', 'admin'], true)) {
        $reason = 'manual';
    }

    $quizId = $att['quiz_id'] ?? '0';

    $rawRes = Lua::execute(
        $redis,
        'submit',
        [
            "att:{$aid}",
            'deadlines',
            'dirty_att',
            'fq',
            "qstat:{$quizId}",
        ],
        [
            $aid,
            (string) $nowMs,
            $reason,
        ]
    );

    $res = is_string($rawRes) ? json_decode($rawRes, true) : $rawRes;
    if (isset($res['error'])) {
        HotRouter::error(400, (string) $res['error'], 'Submission rejected', $nowMs);
        return;
    }

    HotRouter::json(200, [
        'ok' => true,
        'submitted' => true,
        'submitted_ms' => (int) ($res['submitted_ms'] ?? $nowMs),
        'already_completed' => (bool) ($res['already_completed'] ?? false),
        'server_now_ms' => $nowMs,
    ], $nowMs);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$router->dispatch($method, $uri, $config, $redis);
