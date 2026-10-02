<?php

declare(strict_types=1);

namespace App\Hot;

/**
 * Fast APCu cache wrapper with in-memory fallback.
 * Used on the hot path for immutable quiz metadata and structure verification.
 */
class ApcuCache
{
    /** @var array<string, mixed> In-memory fallback */
    private static array $memoryCache = [];

    /**
     * Fetch a value from APCu or fallback.
     *
     * @param string $key
     * @param bool|null $success
     * @return mixed
     */
    public static function get(string $key, ?bool &$success = null): mixed
    {
        if (function_exists('apcu_fetch')) {
            return apcu_fetch($key, $success);
        }

        if (array_key_exists($key, self::$memoryCache)) {
            $success = true;
            return self::$memoryCache[$key];
        }

        $success = false;
        return false;
    }

    /**
     * Store a value in APCu or fallback.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttlSeconds (0 = indefinite)
     * @return bool
     */
    public static function set(string $key, mixed $value, int $ttlSeconds = 0): bool
    {
        if (function_exists('apcu_store')) {
            return apcu_store($key, $value, $ttlSeconds);
        }

        self::$memoryCache[$key] = $value;
        return true;
    }

    /**
     * Delete a key.
     */
    public static function delete(string $key): bool
    {
        if (function_exists('apcu_delete')) {
            return apcu_delete($key);
        }

        unset(self::$memoryCache[$key]);
        return true;
    }

    /**
     * Clear all cached values.
     */
    public static function clear(): bool
    {
        if (function_exists('apcu_clear_cache')) {
            return apcu_clear_cache();
        }

        self::$memoryCache = [];
        return true;
    }
}
