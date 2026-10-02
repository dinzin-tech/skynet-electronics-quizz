<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env variables safely for testing
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
}

// Stub Redis class if ext-redis is not installed in the current PHP CLI environment
if (!class_exists('Redis')) {
    class Redis
    {
        public const OPT_SERIALIZER = 1;
        public const SERIALIZER_NONE = 0;

        public function pconnect(string $host, int $port = 6379, float $timeout = 0.0): bool
        {
            return false;
        }

        public function auth(string $password): bool
        {
            return true;
        }

        public function setOption(int $option, mixed $value): bool
        {
            return true;
        }

        public function incr(string $key): int|false
        {
            return 1;
        }

        public function expire(string $key, int $ttl): bool
        {
            return true;
        }

        public function ttl(string $key): int|false
        {
            return 0;
        }

        public function del(string ...$keys): int|false
        {
            return 1;
        }

        public function setex(string $key, int $ttl, mixed $value): bool
        {
            return true;
        }

        public function exists(string ...$keys): int|bool
        {
            return 1;
        }

        public function eval(string $script, array $args = [], int $numKeys = 0): mixed
        {
            return null;
        }

        public function evalSha(string $sha, array $args = [], int $numKeys = 0): mixed
        {
            return null;
        }

        public function script(string $command, mixed ...$args): mixed
        {
            return '';
        }

        public function close(): bool
        {
            return true;
        }
    }
}
