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

        public const PIPELINE = 2;

        public function get(string $key): mixed
        {
            return false;
        }

        public function set(string $key, mixed $value, mixed $options = null): bool
        {
            return true;
        }

        public function hGet(string $key, string $field): mixed
        {
            return false;
        }

        public function hSet(string $key, string $field, mixed $value): int|false
        {
            return 1;
        }

        public function hMSet(string $key, array $fieldValues): bool
        {
            return true;
        }

        public function hGetAll(string $key): array
        {
            return [];
        }

        public function hIncrBy(string $key, string $field, int $value): int
        {
            return 1;
        }

        public function zAdd(string $key, float|int $score, string $value): int|false
        {
            return 1;
        }

        public function zRem(string $key, string ...$values): int|false
        {
            return 1;
        }

        public function zRangeByScore(string $key, mixed $start, mixed $end, array $options = []): array
        {
            return [];
        }

        public function sAdd(string $key, string ...$values): int|false
        {
            return 1;
        }

        public function sPop(string $key, int $count = 1): mixed
        {
            return [];
        }

        public function sMembers(string $key): array
        {
            return [];
        }

        public function rPush(string $key, string ...$values): int|false
        {
            return 1;
        }

        public function lPop(string $key): mixed
        {
            return null;
        }

        public function multi(int $mode = 1): static
        {
            return $this;
        }

        public function exec(): array|false
        {
            return [];
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
