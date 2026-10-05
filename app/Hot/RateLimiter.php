<?php

declare(strict_types=1);

namespace App\Hot;

use Redis as PhpRedis;

/**
 * User-keyed rate limiter using Redis INCR + TTL.
 * Strictly keyed by user ID (uid), NEVER by client IP address.
 * (Corporate offices share common NAT gateways).
 */
class RateLimiter
{
    /**
     * Check and increment the rate limit for a specific user and bucket.
     *
     * @param mixed $redis Redis connection
     * @param int|string $uid User/Employee unique ID
     * @param string $bucket Category name (e.g., 'save', 'start')
     * @param int $maxRequests Maximum allowed requests in the time window
     * @param int $windowSeconds Length of the time window in seconds
     * @return array{allowed: bool, remaining: int, retry_after: int, current: int}
     */
    public static function check(
        $redis,
        int|string $uid,
        string $bucket,
        int $maxRequests,
        int $windowSeconds
    ): array {
        $key = "rl:{$uid}:{$bucket}";

        $current = (int) $redis->incr($key);
        if ($current === 1) {
            $redis->expire($key, $windowSeconds);
        }

        $ttl = (int) $redis->ttl($key);
        if ($ttl < 0) {
            $redis->expire($key, $windowSeconds);
            $ttl = $windowSeconds;
        }

        $allowed = ($current <= $maxRequests);
        $remaining = max(0, $maxRequests - $current);
        $retryAfter = $allowed ? 0 : max(1, $ttl);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'current' => $current,
        ];
    }

    /**
     * Reset rate limit bucket for a user (useful in tests and admin actions).
     */
    public static function reset($redis, int|string $uid, string $bucket): bool
    {
        $key = "rl:{$uid}:{$bucket}";
        return (bool) $redis->del($key);
    }
}
