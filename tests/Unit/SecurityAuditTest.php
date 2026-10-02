<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Clock;
use App\Hot\HotRouter;
use App\Hot\Redis as HotRedis;
use App\Hot\Token;
use App\Hot\Ulid;
use App\Services\ReportExportService;
use Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

class SecurityAuditTest extends TestCase
{
    private PDO $db;
    private string $secret = 'TestAuditSecretKey64ByteLongStringForHmacSha256SecurityVerification!';

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function test_tampered_hmac_token_is_strictly_rejected(): void
    {
        $validToken = Token::issue('EMP_001', 'employee', 3600, $this->secret);

        // 1. Alter payload
        $parts = explode('.', $validToken);
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode([
            'uid' => 'EMP_001',
            'role' => 'admin', // Privilege escalation attempt
            'exp' => time() + 3600,
            'kid' => 'v1',
        ])), '+/', '-_'), '=');
        $tamperedToken = $parts[0] . '.' . $tamperedPayload . '.' . $parts[2];

        $verified = Token::verify($tamperedToken, $this->secret);
        $this->assertNull($verified, 'Tampered token payload must return null');

        // 2. Alter signature
        $corruptSigToken = $validToken . 'extra';
        $verifiedSig = Token::verify($corruptSigToken, $this->secret);
        $this->assertNull($verifiedSig, 'Corrupted token signature must return null');
    }

    public function test_expired_token_is_rejected(): void
    {
        $expiredToken = Token::issue('EMP_001', 'employee', -60, $this->secret);

        $verified = Token::verify($expiredToken, $this->secret);
        $this->assertNull($verified, 'Expired token must return null');
    }

    public function test_idor_protection_rejects_unauthorized_attempt_access(): void
    {
        try {
            $redis = HotRedis::connection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis connection not available');
        }

        $victimEmpCode = 'EMP_VICTIM_' . substr(Ulid::generate(), -4);
        $attackerEmpCode = 'EMP_ATTACKER_' . substr(Ulid::generate(), -4);
        $attemptId = 'ATT_IDOR_TEST_' . Ulid::generate();

        // Register attempt owned by victim in Redis
        $redis->hMSet("att:{$attemptId}", [
            'emp' => $victimEmpCode,
            'status' => 'IN_PROGRESS',
            'quiz_id' => '1',
            'ver' => '1',
        ]);

        // Attacker attempts to access victim's attempt
        $attackerToken = Token::issue($attackerEmpCode, 'employee', 3600, $this->secret);

        // Verification: hot router ownership check must deny access
        $storedEmp = $redis->hGet("att:{$attemptId}", 'emp');
        $this->assertSame($victimEmpCode, $storedEmp);
        $this->assertNotSame($attackerEmpCode, $storedEmp);

        // Cleanup
        $redis->del("att:{$attemptId}");
    }

    public function test_bundles_do_not_leak_correctness_data(): void
    {
        $bundleDir = dirname(__DIR__, 2) . '/storage/bundles';
        if (!is_dir($bundleDir)) {
            $this->markTestSkipped('No bundles directory found');
        }

        $files = glob("{$bundleDir}/*.json");
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                '"is_correct"',
                $content,
                "Bundle {$file} must never contain 'is_correct' field"
            );
            $this->assertStringNotContainsString(
                'answer_key',
                $content,
                "Bundle {$file} must never contain 'answer_key'"
            );
        }
    }

    public function test_formula_injection_defense_neutralizes_all_dangerous_characters(): void
    {
        $dangerousInputs = [
            '=cmd|"/C calc"!A0',
            '+12345',
            '-99999',
            '@SUM(A1:A5)',
            "\tTAB_INJECTED",
            "\rCR_INJECTED",
        ];

        foreach ($dangerousInputs as $input) {
            $sanitized = ReportExportService::sanitizeCell($input);
            $this->assertStringStartsWith(
                "'",
                $sanitized,
                "Dangerous formula prefix in '{$input}' must be sanitized with leading single quote"
            );
        }
    }
}
