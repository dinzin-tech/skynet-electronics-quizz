<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Hot\RateLimiter;
use PHPUnit\Framework\TestCase;
use Redis;

class RateLimiterTest extends TestCase
{
    public function test_allows_requests_within_limit(): void
    {
        $redis = $this->createMock(Redis::class);

        // First request: count = 1
        $redis->expects($this->once())
            ->method('incr')
            ->with('rl:101:save')
            ->willReturn(1);

        $redis->expects($this->once())
            ->method('expire')
            ->with('rl:101:save', 10)
            ->willReturn(true);

        $redis->expects($this->once())
            ->method('ttl')
            ->with('rl:101:save')
            ->willReturn(10);

        $result = RateLimiter::check($redis, 101, 'save', 20, 10);

        $this->assertTrue($result['allowed']);
        $this->assertSame(19, $result['remaining']);
        $this->assertSame(0, $result['retry_after']);
        $this->assertSame(1, $result['current']);
    }

    public function test_blocks_requests_exceeding_limit(): void
    {
        $redis = $this->createMock(Redis::class);

        // 21st request exceeding limit of 20
        $redis->expects($this->once())
            ->method('incr')
            ->with('rl:101:save')
            ->willReturn(21);

        // Not the first request, so expire is not called directly here
        $redis->expects($this->once())
            ->method('ttl')
            ->with('rl:101:save')
            ->willReturn(6); // 6 seconds remaining in current window

        $result = RateLimiter::check($redis, 101, 'save', 20, 10);

        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(6, $result['retry_after']);
        $this->assertSame(21, $result['current']);
    }

    public function test_resets_rate_limit_bucket(): void
    {
        $redis = $this->createMock(Redis::class);

        $redis->expects($this->once())
            ->method('del')
            ->with('rl:101:save')
            ->willReturn(1);

        $this->assertTrue(RateLimiter::reset($redis, 101, 'save'));
    }
}
