<?php

declare(strict_types=1);

namespace App\Hot;

use DateTimeImmutable;
use DateTimeZone;

/**
 * High-precision server clock for the hot path.
 * All internal timestamps are in UTC.
 */
class Clock
{
    private static ?int $mockNowMs = null;

    /**
     * Get current server time in milliseconds since Unix epoch (UTC).
     */
    public static function nowMs(): int
    {
        if (self::$mockNowMs !== null) {
            return self::$mockNowMs;
        }

        return (int) round(microtime(true) * 1000);
    }

    /**
     * Get current server DateTime in UTC.
     */
    public static function nowUtc(): DateTimeImmutable
    {
        if (self::$mockNowMs !== null) {
            $seconds = (int) (self::$mockNowMs / 1000);
            $micro = (self::$mockNowMs % 1000) * 1000;
            return DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%d.%06d', $seconds, $micro),
                new DateTimeZone('UTC')
            );
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Format a millisecond timestamp as ISO 8601 string.
     */
    public static function formatIsoMs(int $ms): string
    {
        $seconds = (int) ($ms / 1000);
        $millis = $ms % 1000;
        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }

    /**
     * Set a mock time in milliseconds for deterministic unit testing.
     */
    public static function setMockNowMs(?int $mockNowMs): void
    {
        self::$mockNowMs = $mockNowMs;
    }
}
