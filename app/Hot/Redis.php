<?php

declare(strict_types=1);

namespace App\Hot;

use Redis as PhpRedis;
use RuntimeException;

/**
 * High-performance Redis connection manager for the hot path.
 * Connects over unix socket with pconnect() for near-zero connection overhead.
 */
class Redis
{
    /** @var mixed */
    private static mixed $instance = null;

    /**
     * Get default connection using config/hot.php or environment.
     */
    public static function connection(): mixed
    {
        $configFile = dirname(__DIR__, 2) . '/config/hot.php';
        $config = file_exists($configFile) ? require $configFile : [
            'redis_host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'redis_port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'redis_password' => $_ENV['REDIS_PASSWORD'] ?? '',
            'redis_socket' => $_ENV['REDIS_SOCKET'] ?? '',
            'redis_timeout' => 1.5,
        ];
        return self::getConnection($config);
    }

    /**
     * Get or establish the persistent Redis connection.
     *
     * @param array<string, mixed> $config
     * @return mixed
     */
    public static function getConnection(array $config): mixed
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = $config['redis_host'] ?? '127.0.0.1';
        $port = (int) ($config['redis_port'] ?? 6379);
        $socket = $config['redis_socket'] ?? '';
        $timeout = (float) ($config['redis_timeout'] ?? 1.5);
        $password = $config['redis_password'] ?? '';

        if (!class_exists('Redis')) {
            $client = new RedisSocketClient($host, $port, $timeout);
            if ($password !== '') {
                $client->auth($password);
            }
            self::$instance = $client;
            return self::$instance;
        }

        $redis = new PhpRedis();
        $connected = false;

        // 1. Try persistent unix socket connection first (production hot path)
        if ($socket !== '' && file_exists($socket)) {
            $connected = $redis->pconnect($socket, -1, $timeout);
        }

        // 2. Fall back to TCP connection (local dev / test / docker)
        if (!$connected) {
            $connected = $redis->pconnect($host, $port, $timeout);
        }

        if (!$connected) {
            throw new RuntimeException('Failed to connect to Redis instance.');
        }

        // Optional password authentication
        $password = $config['redis_password'] ?? '';
        if ($password !== '') {
            if (!$redis->auth($password)) {
                throw new RuntimeException('Redis authentication failed.');
            }
        }

        $redis->setOption(PhpRedis::OPT_SERIALIZER, PhpRedis::SERIALIZER_NONE);
        self::$instance = $redis;

        return self::$instance;
    }

    /**
     * Set a custom Redis instance (useful for unit testing with mocks).
     */
    public static function setInstance(mixed $redis): void
    {
        self::$instance = $redis;
    }

    /**
     * Close and reset the connection instance.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            try {
                self::$instance->close();
            } catch (\Throwable $e) {
                // Ignore close errors
            }
            self::$instance = null;
        }
    }
}
