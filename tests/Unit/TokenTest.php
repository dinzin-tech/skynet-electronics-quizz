<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\Clock;
use App\Hot\Token;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    private string $secret = 'test_secret_key_0123456789abcdef';

    protected function tearDown(): void
    {
        Clock::setMockNowMs(null);
    }

    public function test_issues_and_verifies_valid_token(): void
    {
        Clock::setMockNowMs(1700000000000); // fixed time: 1700000000 sec

        $token = Token::issue(101, 'employee', 3600, $this->secret, 'v1');

        $this->assertNotEmpty($token);
        $this->assertCount(2, explode('.', $token));

        $payload = Token::verify($token, $this->secret, 'v1');

        $this->assertNotNull($payload);
        $this->assertSame(101, $payload['uid']);
        $this->assertSame('employee', $payload['role']);
        $this->assertSame('v1', $payload['kid']);
        $this->assertSame(1700003600, $payload['exp']);
    }

    public function test_rejects_tampered_payload(): void
    {
        Clock::setMockNowMs(1700000000000);
        $token = Token::issue(101, 'employee', 3600, $this->secret, 'v1');

        [$payloadEncoded, $sigEncoded] = explode('.', $token);

        // Tamper payload to elevate role to admin
        $tamperedPayload = json_decode(Token::base64UrlDecode($payloadEncoded), true);
        $tamperedPayload['role'] = 'admin';
        $tamperedEncoded = Token::base64UrlEncode(json_encode($tamperedPayload));

        $tamperedToken = "{$tamperedEncoded}.{$sigEncoded}";

        $this->assertNull(Token::verify($tamperedToken, $this->secret, 'v1'));
    }

    public function test_rejects_tampered_signature(): void
    {
        Clock::setMockNowMs(1700000000000);
        $token = Token::issue(101, 'employee', 3600, $this->secret, 'v1');

        [$payloadEncoded, $sigEncoded] = explode('.', $token);
        $tamperedSig = $sigEncoded . 'x';

        $this->assertNull(Token::verify("{$payloadEncoded}.{$tamperedSig}", $this->secret, 'v1'));
    }

    public function test_rejects_wrong_secret(): void
    {
        Clock::setMockNowMs(1700000000000);
        $token = Token::issue(101, 'employee', 3600, $this->secret, 'v1');

        $this->assertNull(Token::verify($token, 'different_wrong_secret', 'v1'));
    }

    public function test_rejects_expired_token(): void
    {
        Clock::setMockNowMs(1700000000000); // 1700000000s
        $token = Token::issue(101, 'employee', 60, $this->secret, 'v1'); // exp = 1700000060

        // Advance clock past expiration
        Clock::setMockNowMs(1700000061000); // 1700000061s

        $this->assertNull(Token::verify($token, $this->secret, 'v1'));
    }

    public function test_rejects_wrong_kid(): void
    {
        Clock::setMockNowMs(1700000000000);
        $token = Token::issue(101, 'employee', 3600, $this->secret, 'v1');

        // Request verification expecting kid 'v2'
        $this->assertNull(Token::verify($token, $this->secret, 'v2'));
    }

    public function test_rejects_malformed_tokens(): void
    {
        $this->assertNull(Token::verify('', $this->secret));
        $this->assertNull(Token::verify('single_part_token', $this->secret));
        $this->assertNull(Token::verify('part1.part2.part3', $this->secret));
        $this->assertNull(Token::verify('invalid_base64$.signature', $this->secret));

        // Valid base64 but invalid JSON
        $badJson = Token::base64UrlEncode('not_json');
        $sig = Token::base64UrlEncode(hash_hmac('sha256', $badJson, $this->secret, true));
        $this->assertNull(Token::verify("{$badJson}.{$sig}", $this->secret));
    }
}
