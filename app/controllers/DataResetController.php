<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Csrf;
use App\Services\DataPurgeService;
use App\Hot\Redis as HotRedis;
use Core\Controller;
use Core\Database;
use Core\Http\Request;
use Core\Http\Response;
use Core\Session;
use PDO;

/**
 * DataResetController
 *
 * Thin admin controller for the Data Reset feature.
 * Security model:
 *   1. requireAdmin()  – session check identical to AdminController
 *   2. passcode gate   – PASS_CODE env var (min 8 chars); fail-closed
 *   3. CSRF            – Csrf::verify() on every POST
 *   4. Throttle        – 5 wrong attempts → 15-min lock (Redis, fallback session)
 *   5. Unlock window   – 10 minutes from correct passcode, bound to admin id
 *
 * @Route(path="/admin/data-reset", methods="GET", name="admin.data_reset")
 * @Route(path="/admin/data-reset/unlock", methods="POST", name="admin.data_reset.unlock")
 * @Route(path="/admin/data-reset/lock", methods="POST", name="admin.data_reset.lock")
 * @Route(path="/admin/data-reset/execute", methods="POST", name="admin.data_reset.execute")
 */
class DataResetController extends Controller
{
    private PDO $db;
    private DataPurgeService $purgeService;
    /** @var mixed */
    private $redis;
    private string $basePath;

    // ── Throttle / window constants ────────────────────────────────────────
    private const MAX_ATTEMPTS      = 5;
    private const LOCK_SECONDS      = 900;   // 15 minutes
    private const UNLOCK_SECONDS    = 600;   // 10 minutes
    private const THROTTLE_SESSION  = 'purge_throttle';
    private const UNLOCK_UNTIL_KEY  = 'purge_unlocked_until';
    private const UNLOCK_ADMIN_KEY  = 'purge_unlocked_admin';
    private const MIN_PASSCODE_LEN  = 8;

    public function __construct()
    {
        parent::__construct();
        if (session_status() === PHP_SESSION_NONE) {
            Session::start();
        }

        $this->db       = Database::getInstance()->getConnection();
        $this->basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

        try {
            $this->redis = HotRedis::connection();
        } catch (\Throwable) {
            $this->redis = null;
        }

        $extraLogDirs    = ['/var/log/corpquiz'];
        $this->purgeService = new DataPurgeService(
            $this->db,
            $this->redis,
            $this->basePath,
            $extraLogDirs
        );
    }

    // -----------------------------------------------------------------------
    // Routes
    // -----------------------------------------------------------------------

    /**
     * @Route(path="/admin/data-reset", methods="GET", name="admin.data_reset")
     */
    public function index(Request $request): Response
    {
        if ($redirect = $this->requireAdmin()) {
            return $redirect;
        }

        $response = $this->buildNoStoreResponse();

        $passCode = $this->getPassCode();
        if ($passCode === null) {
            return $this->renderPage([
                'disabled'      => true,
                'disabled_msg'  => 'Data Reset is disabled: set PASS_CODE in .env (min 8 characters).',
                'unlocked'      => false,
                'csrf_token'    => Csrf::token(),
                'current_route' => 'data_reset',
                'admin'         => $this->getAdminUser(),
            ]);
        }

        $unlocked = $this->isUnlocked();
        $data     = [
            'disabled'        => false,
            'unlocked'        => $unlocked,
            'csrf_token'      => Csrf::token(),
            'current_route'   => 'data_reset',
            'admin'           => $this->getAdminUser(),
            'throttled'       => $this->isThrottled(),
            'throttle_ttl'    => $this->throttleTtl(),
            'manifest'        => DataPurgeService::MANIFEST,
            'flash'           => Session::get('__purge_flash'),
        ];
        Session::delete('__purge_flash');

        if ($unlocked) {
            $data['heartbeats'] = $this->purgeService->workerHeartbeats();
            $data['live']       = $this->purgeService->checkLiveActivity();
            $data['previews']   = [];
            foreach (array_keys(DataPurgeService::MANIFEST) as $scope) {
                try {
                    $data['previews'][$scope] = $this->purgeService->preview($scope);
                } catch (\Throwable $e) {
                    $data['previews'][$scope] = ['error' => $e->getMessage()];
                }
            }
        }

        return $this->renderPage($data);
    }

    /**
     * @Route(path="/admin/data-reset/unlock", methods="POST", name="admin.data_reset.unlock")
     */
    public function unlock(Request $request): Response
    {
        if ($redirect = $this->requireAdmin()) {
            return $redirect;
        }

        $passCode = $this->getPassCode();
        if ($passCode === null) {
            return $this->forbidden();
        }

        $submitted = (string) ($request->get('_csrf') ?? ($_POST['_csrf'] ?? ''));
        if (!Csrf::verify($submitted)) {
            $this->flash('error', 'Invalid CSRF token. Please reload and try again.');
            return $this->redirect('/admin/data-reset');
        }

        if ($this->isThrottled()) {
            $mins = (int) ceil($this->throttleTtl() / 60);
            $this->flash('error', "Too many wrong attempts. Try again in {$mins} minute(s).");
            return $this->redirect('/admin/data-reset');
        }

        $given = (string) ($request->get('passcode') ?? ($_POST['passcode'] ?? ''));

        if (!hash_equals(hash('sha256', $passCode), hash('sha256', $given))) {
            $this->incrementThrottle();
            if ($this->isThrottled()) {
                $this->flash('error', 'Too many wrong attempts. Locked for 15 minutes.');
            } else {
                $this->flash('error', 'Incorrect passcode.');
            }
            return $this->redirect('/admin/data-reset');
        }

        $this->resetThrottle();
        $admin = $this->getAdminUser();
        Session::set(self::UNLOCK_UNTIL_KEY, time() + self::UNLOCK_SECONDS);
        Session::set(self::UNLOCK_ADMIN_KEY, $admin['id'] ?? 0);
        $this->flash('success', 'Unlocked for 10 minutes.');
        return $this->redirect('/admin/data-reset');
    }

    /**
     * @Route(path="/admin/data-reset/lock", methods="POST", name="admin.data_reset.lock")
     */
    public function lock(Request $request): Response
    {
        if ($redirect = $this->requireAdmin()) {
            return $redirect;
        }

        $submitted = (string) ($request->get('_csrf') ?? ($_POST['_csrf'] ?? ''));
        if (!Csrf::verify($submitted)) {
            $this->flash('error', 'Invalid CSRF token.');
            return $this->redirect('/admin/data-reset');
        }

        $this->doLock();
        $this->flash('success', 'Data Reset locked.');
        return $this->redirect('/admin/data-reset');
    }

    /**
     * @Route(path="/admin/data-reset/execute", methods="POST", name="admin.data_reset.execute")
     */
    public function execute(Request $request): Response
    {
        if ($redirect = $this->requireAdmin()) {
            return $redirect;
        }

        if ($this->getPassCode() === null) {
            return $this->forbidden();
        }

        // CSRF
        $submitted = (string) ($request->get('_csrf') ?? ($_POST['_csrf'] ?? ''));
        if (!Csrf::verify($submitted)) {
            return $this->forbidden('Invalid CSRF token.');
        }

        // Unlock window
        if (!$this->isUnlocked()) {
            return $this->forbidden('Session not unlocked. Please enter the passcode first.');
        }

        // Scope validation
        $scope = (string) ($request->get('scope') ?? ($_POST['scope'] ?? ''));
        $manifest = DataPurgeService::MANIFEST;
        if (!isset($manifest[$scope])) {
            return $this->forbidden('Unknown scope.');
        }

        // Confirmation phrase
        $phrase         = trim((string) ($request->get('confirm_phrase') ?? ($_POST['confirm_phrase'] ?? '')));
        $expectedPhrase = $manifest[$scope]['confirm_phrase'];
        if ($phrase !== $expectedPhrase) {
            $this->flash('error', "Confirmation phrase incorrect. Type exactly: {$expectedPhrase}");
            return $this->redirect('/admin/data-reset');
        }

        // Live-attempt guard
        if ($manifest[$scope]['needs_force']) {
            $live  = $this->purgeService->checkLiveActivity();
            $force = (string) ($request->get('force') ?? ($_POST['force'] ?? '')) === '1';
            if (($live['live_attempts'] || $live['open_windows']) && !$force) {
                $msg = 'Live attempts or open quiz windows detected. '
                    . 'Check the "I understand" box to proceed.';
                $this->flash('error', $msg);
                return $this->redirect('/admin/data-reset');
            }
        }

        $admin = $this->getAdminUser();
        try {
            $steps = $this->purgeService->execute($scope, [
                'admin_id' => (int) ($admin['id'] ?? 0),
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
            Csrf::regenerate();
            $this->doLock(); // re-lock after successful purge
            $this->flash('result', ['scope' => $scope, 'steps' => $steps]);
        } catch (\Throwable $e) {
            $this->flash('error', 'Purge failed: ' . $e->getMessage());
        }

        return $this->redirect('/admin/data-reset');
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function requireAdmin(): ?Response
    {
        if (!$this->getAdminUser()) {
            return $this->redirect('/admin/login');
        }
        return null;
    }

    private function getAdminUser(): ?array
    {
        return Session::get('admin_user');
    }

    private function getPassCode(): ?string
    {
        $pc = $_ENV['PASS_CODE'] ?? '';
        if (!is_string($pc) || strlen($pc) < self::MIN_PASSCODE_LEN) {
            return null;
        }
        return $pc;
    }

    private function isUnlocked(): bool
    {
        $until   = (int) (Session::get(self::UNLOCK_UNTIL_KEY) ?? 0);
        $adminId = (int) (Session::get(self::UNLOCK_ADMIN_KEY) ?? -1);
        $current = (int) (($this->getAdminUser())['id'] ?? 0);

        return $until > time() && $adminId === $current;
    }

    private function doLock(): void
    {
        Session::delete(self::UNLOCK_UNTIL_KEY);
        Session::delete(self::UNLOCK_ADMIN_KEY);
    }

    // ── Throttle ──────────────────────────────────────────────────────────

    private function throttleKey(): string
    {
        $admin = $this->getAdminUser();
        $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return 'purge_throttle:' . md5(($admin['id'] ?? '0') . ':' . $ip);
    }

    private function isThrottled(): bool
    {
        $key = $this->throttleKey();

        // Redis first
        if ($this->redis !== null) {
            try {
                $cnt = (int) $this->redis->get($key);
                return $cnt >= self::MAX_ATTEMPTS;
            } catch (\Throwable) {
                // fall through to session
            }
        }

        // Session fallback
        $sess = (array) (Session::get(self::THROTTLE_SESSION) ?? []);
        $cnt  = (int) ($sess['count'] ?? 0);
        $exp  = (int) ($sess['expires'] ?? 0);
        if ($exp > 0 && time() > $exp) {
            Session::delete(self::THROTTLE_SESSION);
            return false;
        }
        return $cnt >= self::MAX_ATTEMPTS;
    }

    private function throttleTtl(): int
    {
        $key = $this->throttleKey();

        if ($this->redis !== null) {
            try {
                $ttl = (int) $this->redis->ttl($key);
                return max(0, $ttl);
            } catch (\Throwable) {
                // fall through
            }
        }

        $sess = (array) (Session::get(self::THROTTLE_SESSION) ?? []);
        $exp  = (int) ($sess['expires'] ?? 0);
        return max(0, $exp - time());
    }

    private function incrementThrottle(): void
    {
        $key = $this->throttleKey();

        if ($this->redis !== null) {
            try {
                $cnt = (int) $this->redis->incr($key);
                if ($cnt === 1) {
                    $this->redis->expire($key, self::LOCK_SECONDS);
                }
                return;
            } catch (\Throwable) {
                // fall through
            }
        }

        // Session fallback
        $sess = (array) (Session::get(self::THROTTLE_SESSION) ?? []);
        $cnt  = (int) ($sess['count'] ?? 0) + 1;
        $exp  = $cnt === 1 ? time() + self::LOCK_SECONDS : (int) ($sess['expires'] ?? time() + self::LOCK_SECONDS);
        Session::set(self::THROTTLE_SESSION, ['count' => $cnt, 'expires' => $exp]);
    }

    private function resetThrottle(): void
    {
        $key = $this->throttleKey();
        if ($this->redis !== null) {
            try {
                $this->redis->del($key);
            } catch (\Throwable) {
                // ignore
            }
        }
        Session::delete(self::THROTTLE_SESSION);
    }

    // ── Response helpers ──────────────────────────────────────────────────

    private function buildNoStoreResponse(): Response
    {
        // We just need a marker; headers are set after render
        return new Response();
    }

    private function renderPage(array $data): Response
    {
        $response = $this->render('admin/data_reset/index.html.twig', $data);
        if (method_exists($response, 'setHeader')) {
            $response->setHeader('Cache-Control', 'no-store');
        } elseif (method_exists($response, 'withHeader')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        } else {
            header('Cache-Control: no-store');
        }
        return $response;
    }

    private function forbidden(string $msg = 'Forbidden'): Response
    {
        if (method_exists($this, 'json')) {
            return $this->json(['error' => $msg], 403);
        }
        $response = $this->render('admin/data_reset/index.html.twig', [
            'disabled'      => true,
            'disabled_msg'  => $msg,
            'unlocked'      => false,
            'csrf_token'    => Csrf::token(),
            'current_route' => 'data_reset',
            'admin'         => $this->getAdminUser(),
        ]);
        http_response_code(403);
        header('Cache-Control: no-store');
        return $response;
    }

    private function flash(string $key, mixed $value): void
    {
        Session::set('__purge_flash', [$key => $value]);
    }
}
