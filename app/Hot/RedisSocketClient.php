<?php

declare(strict_types=1);

namespace App\Hot;

use RuntimeException;

/**
 * Pure-PHP lightweight Redis client using standard PHP stream sockets and RESP protocol.
 * Acts as an automatic fallback when the PECL C extension 'redis' is not installed (e.g. Windows dev).
 */
class RedisSocketClient
{
    /** @var resource|null */
    private $socket = null;
    private string $host;
    private int $port;
    private float $timeout;

    public function __construct(string $host = '127.0.0.1', int $port = 6379, float $timeout = 1.5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->connect($host, $port, $timeout);
    }

    public function connect(string $host, int $port = 6379, float $timeout = 1.5): bool
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new RuntimeException("Cannot connect to Redis at {$host}:{$port} ({$errstr})");
        }

        stream_set_timeout($fp, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000));
        $this->socket = $fp;
        return true;
    }

    public function pconnect(string $host, int $port = 6379, float $timeout = 1.5): bool
    {
        return $this->connect($host, $port, $timeout);
    }

    public function setOption(int $option, mixed $value): void
    {
        // No-op for compatibility
    }

    public function auth(string $password): bool
    {
        $res = $this->executeCommand(['AUTH', $password]);
        return $res === 'OK';
    }

    public function ping(): string
    {
        return (string) $this->executeCommand(['PING']);
    }

    public function get(string $key): mixed
    {
        return $this->executeCommand(['GET', $key]);
    }

    public function set(string $key, mixed $val): bool
    {
        return $this->executeCommand(['SET', $key, (string) $val]) === 'OK';
    }

    public function setEx(string $key, int $ttl, mixed $val): bool
    {
        return $this->executeCommand(['SETEX', $key, (string) $ttl, (string) $val]) === 'OK';
    }

    public function del(string ...$keys): int
    {
        if (empty($keys)) {
            return 0;
        }
        return (int) $this->executeCommand(array_merge(['DEL'], $keys));
    }

    public function exists(string ...$keys): int
    {
        if (empty($keys)) {
            return 0;
        }
        return (int) $this->executeCommand(array_merge(['EXISTS'], $keys));
    }

    public function hGet(string $key, string $field): mixed
    {
        return $this->executeCommand(['HGET', $key, $field]);
    }

    public function hGetAll(string $key): array
    {
        $raw = $this->executeCommand(['HGETALL', $key]);
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        $count = count($raw);
        for ($i = 0; $i < $count; $i += 2) {
            if (isset($raw[$i + 1])) {
                $result[(string) $raw[$i]] = (string) $raw[$i + 1];
            }
        }
        return $result;
    }

    public function hMSet(string $key, array $pairs): bool
    {
        $cmd = ['HMSET', $key];
        foreach ($pairs as $k => $v) {
            $cmd[] = (string) $k;
            $cmd[] = (string) $v;
        }
        return $this->executeCommand($cmd) === 'OK';
    }

    public function hIncrBy(string $key, string $field, int $value): int
    {
        return (int) $this->executeCommand(['HINCRBY', $key, $field, (string) $value]);
    }

    public function hIncrByFloat(string $key, string $field, float $value): float
    {
        return (float) $this->executeCommand(['HINCRBYFLOAT', $key, $field, (string) $value]);
    }

    public function sAdd(string $key, mixed ...$members): int
    {
        $cmd = ['SADD', $key];
        foreach ($members as $m) {
            $cmd[] = (string) $m;
        }
        return (int) $this->executeCommand($cmd);
    }

    public function sMembers(string $key): array
    {
        $res = $this->executeCommand(['SMEMBERS', $key]);
        return is_array($res) ? $res : [];
    }

    public function sRem(string $key, mixed ...$members): int
    {
        $cmd = ['SREM', $key];
        foreach ($members as $m) {
            $cmd[] = (string) $m;
        }
        return (int) $this->executeCommand($cmd);
    }

    public function sCard(string $key): int
    {
        return (int) $this->executeCommand(['SCARD', $key]);
    }

    public function zAdd(string $key, float|int $score, mixed $member): int
    {
        return (int) $this->executeCommand(['ZADD', $key, (string) $score, (string) $member]);
    }

    public function zRem(string $key, mixed $member): int
    {
        return (int) $this->executeCommand(['ZREM', $key, (string) $member]);
    }

    public function zRangeByScore(string $key, string|int $min, string|int $max, array $options = []): array
    {
        $cmd = ['ZRANGEBYSCORE', $key, (string) $min, (string) $max];
        if (!empty($options['withscores'])) {
            $cmd[] = 'WITHSCORES';
        }
        if (!empty($options['limit']) && is_array($options['limit'])) {
            $cmd[] = 'LIMIT';
            $cmd[] = (string) ($options['limit'][0] ?? 0);
            $cmd[] = (string) ($options['limit'][1] ?? 10);
        }
        $res = $this->executeCommand($cmd);
        return is_array($res) ? $res : [];
    }

    public function rPush(string $key, mixed ...$values): int
    {
        $cmd = ['RPUSH', $key];
        foreach ($values as $v) {
            $cmd[] = (string) $v;
        }
        return (int) $this->executeCommand($cmd);
    }

    public function lPop(string $key): mixed
    {
        return $this->executeCommand(['LPOP', $key]);
    }

    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        $cmd = ['EVAL', $script, (string) $numKeys];
        foreach ($args as $arg) {
            $cmd[] = (string) $arg;
        }
        return $this->executeCommand($cmd);
    }

    public function evalSha(string $sha, array $args = [], int $numKeys = 0): mixed
    {
        $cmd = ['EVALSHA', $sha, (string) $numKeys];
        foreach ($args as $arg) {
            $cmd[] = (string) $arg;
        }
        return $this->executeCommand($cmd);
    }

    public function script(string $subcommand, string ...$args): mixed
    {
        $cmd = array_merge(['SCRIPT', strtoupper($subcommand)], $args);
        return $this->executeCommand($cmd);
    }

    public function incr(string $key): int
    {
        return (int) $this->executeCommand(['INCR', $key]);
    }

    public function expire(string $key, int $ttl): bool
    {
        return (int) $this->executeCommand(['EXPIRE', $key, (string) $ttl]) === 1;
    }

    public function ttl(string $key): int
    {
        return (int) $this->executeCommand(['TTL', $key]);
    }

    /**
     * Send command using RESP protocol and parse response.
     *
     * @param array<int, string> $args
     */
    private function executeCommand(array $args): mixed
    {
        if (!$this->socket) {
            $this->connect($this->host, $this->port, $this->timeout);
        }

        // Build RESP array
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $str = (string) $arg;
            $payload .= '$' . strlen($str) . "\r\n" . $str . "\r\n";
        }

        $written = @fwrite($this->socket, $payload);
        if ($written === false || $written === 0) {
            // Reconnect once and retry
            $this->connect($this->host, $this->port, $this->timeout);
            fwrite($this->socket, $payload);
        }

        return $this->parseResponse();
    }

    private function parseResponse(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false || $line === '') {
            return false;
        }

        $type = $line[0];
        $data = substr(rtrim($line, "\r\n"), 1);

        switch ($type) {
            case '+': // Simple string
                return $data;

            case '-': // Error
                throw new RuntimeException("Redis Error: {$data}");

            case ':': // Integer
                return (int) $data;

            case '$': // Bulk string
                $len = (int) $data;
                if ($len === -1) {
                    return false; // Null bulk string in Redis protocol returns false in PHPRedis
                }
                $content = '';
                $remaining = $len;
                while ($remaining > 0) {
                    $chunk = fread($this->socket, $remaining);
                    if ($chunk === false) {
                        break;
                    }
                    $content .= $chunk;
                    $remaining -= strlen($chunk);
                }
                // Read trailing \r\n
                fread($this->socket, 2);
                return $content;

            case '*': // Array
                $count = (int) $data;
                if ($count === -1) {
                    return false;
                }
                $array = [];
                for ($i = 0; $i < $count; $i++) {
                    $array[] = $this->parseResponse();
                }
                return $array;

            default:
                throw new RuntimeException("Unknown RESP response type: '{$type}'");
        }
    }

    public function __destruct()
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
