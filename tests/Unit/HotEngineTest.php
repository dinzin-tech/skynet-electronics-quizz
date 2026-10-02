<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Clock;
use App\Hot\HotRouter;
use App\Hot\Token;
use App\Hot\Ulid;
use PHPUnit\Framework\TestCase;

class HotEngineTest extends TestCase
{
    private string $secret = 'test_hot_engine_secret_1234567890123456';
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'app' => [
                'key' => $this->secret,
            ],
        ];
    }

    protected function tearDown(): void
    {
        Clock::setMockNowMs(null);
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI']
        );
    }

    public function test_time_endpoint_returns_now_ms(): void
    {
        $router = new HotRouter();
        $router->get('/api/time', function ($params, $cfg, $redis, $nowMs) {
            HotRouter::json(200, [
                'server_now_ms' => $nowMs,
                'iso' => Clock::formatIsoMs($nowMs),
            ], $nowMs);
        });

        Clock::setMockNowMs(1700000000000);

        // Capture output
        ob_start();
        $redisStub = new \Redis();
        $router->dispatch('GET', '/api/time', $this->config, $redisStub);
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        $this->assertNotNull($data);
        $this->assertSame(1700000000000, $data['server_now_ms']);
    }

    public function test_token_verification_rejects_tampered_or_invalid_signature(): void
    {
        $token = Token::issue('1001', 'employee', 3600, $this->secret);
        $this->assertNotEmpty($token);

        // Valid verify
        $claims = Token::verify($token, $this->secret);
        $this->assertNotNull($claims);
        $this->assertSame('1001', (string) $claims['uid']);
        $this->assertSame('employee', $claims['role']);

        // Wrong secret
        $invalid = Token::verify($token, 'wrong_secret_12345678901234567890');
        $this->assertNull($invalid);

        // Tampered payload
        $parts = explode('.', $token);
        $tampered = 'eyJuZXciOiAidmFsIn0.' . $parts[1];
        $this->assertNull(Token::verify($tampered, $this->secret));
    }
}
