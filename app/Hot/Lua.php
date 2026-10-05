<?php

declare(strict_types=1);

namespace App\Hot;

use Redis as PhpRedis;
use RuntimeException;

/**
 * Atomic Redis Lua script loader and executor for hot operations.
 * Caches script SHA1 hashes and handles EVALSHA with EVAL fallback.
 */
class Lua
{
    /** @var array<string, string> scriptName => sha1 */
    private static array $shaCache = [];

    /** @var array<string, string> scriptName => lua code */
    private static array $codeCache = [];

    private static ?string $luaDirectory = null;

    /**
     * Set the directory where Lua scripts reside.
     */
    public static function setDirectory(string $path): void
    {
        self::$luaDirectory = rtrim($path, '/\\');
    }

    /**
     * Get the directory where Lua scripts reside.
     */
    public static function getDirectory(): string
    {
        if (self::$luaDirectory !== null) {
            return self::$luaDirectory;
        }

        return __DIR__ . '/lua';
    }

    /**
     * Execute a Lua script by name atomically.
     *
     * @param mixed $redis
     * @param string $scriptName e.g. 'start', 'save', 'submit'
     * @param array<int, string> $keys Redis KEYS
     * @param array<int, mixed> $args Redis ARGV
     * @return mixed Lua script return value
     */
    public static function execute($redis, string $scriptName, array $keys = [], array $args = []): mixed
    {
        $sha = self::$shaCache[$scriptName] ?? null;

        if ($sha !== null) {
            try {
                return $redis->evalSha($sha, array_merge($keys, $args), count($keys));
            } catch (\RedisException $e) {
                if (stripos($e->getMessage(), 'NOSCRIPT') === false) {
                    throw $e;
                }
                // Fall through to reload script
            }
        }

        $code = self::loadScriptCode($scriptName);
        $newSha = $redis->script('load', $code);

        if (is_string($newSha)) {
            self::$shaCache[$scriptName] = $newSha;
            return $redis->evalSha($newSha, array_merge($keys, $args), count($keys));
        }

        // Fallback to direct eval if script('load') failed
        return $redis->eval($code, array_merge($keys, $args), count($keys));
    }

    /**
     * Load the raw code of a Lua script file.
     */
    public static function loadScriptCode(string $scriptName): string
    {
        if (isset(self::$codeCache[$scriptName])) {
            return self::$codeCache[$scriptName];
        }

        $filePath = self::getDirectory() . '/' . $scriptName . '.lua';
        if (!file_exists($filePath)) {
            throw new RuntimeException("Lua script not found: {$filePath}");
        }

        $code = (string) file_get_contents($filePath);
        self::$codeCache[$scriptName] = $code;

        return $code;
    }

    /**
     * Reset caches.
     */
    public static function clear(): void
    {
        self::$shaCache = [];
        self::$codeCache = [];
    }
}
