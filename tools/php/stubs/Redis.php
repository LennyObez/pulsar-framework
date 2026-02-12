<?php

/**
 * RedisException stub for Psalm.
 */
class RedisException extends RuntimeException {}

/**
 * Minimal Redis stub for Psalm static analysis.
 *
 * ext-redis is optional (listed in composer.json suggest).
 * This stub provides just enough type information for Psalm
 * to analyze RedisDriver, RedisLock, CacheManager, and RedisHandler.
 */
class Redis
{
    /**
     * @param string $host
     * @param int $port
     * @param float $timeout
     * @return bool
     */
    public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool {}

    /**
     * @param string $key
     * @return string|false
     */
    public function get(string $key): string|false {}

    /**
     * @param string $key
     * @param string $value
     * @param array<string|int, string|int>|null $options
     * @return bool
     */
    public function set(string $key, string $value, ?array $options = null): bool {}

    /**
     * @param string $key
     * @param int $ttl
     * @param string $value
     * @return bool
     */
    public function setex(string $key, int $ttl, string $value): bool {}

    /**
     * @param list<string> $keys
     * @return list<string|false>|false
     */
    public function mget(array $keys): array|false {}

    /**
     * @param string ...$keys
     * @return int
     */
    public function del(string ...$keys): int {}

    /**
     * @param string $key
     * @return int
     */
    public function exists(string $key): int {}

    /**
     * @return bool
     */
    public function flushDB(): bool {}

    /**
     * @param string $key
     * @param int $value
     * @return int
     */
    public function incrBy(string $key, int $value): int {}

    /**
     * @param string $key
     * @param int $value
     * @return int
     */
    public function decrBy(string $key, int $value): int {}

    /**
     * @return self
     */
    public function pipeline(): self {}

    /**
     * @return list<mixed>|false
     */
    public function exec(): array|false {}

    /**
     * @param string $script
     * @param list<string> $args
     * @param int $numKeys
     * @return mixed
     */
    public function eval(string $script, array $args = [], int $numKeys = 0): mixed {}

    /**
     * @param string $key
     * @param int $seconds
     * @return bool
     */
    public function expire(string $key, int $seconds): bool {}

    /**
     * @param string $key
     * @param int $timeout
     * @param string $value
     * @return bool
     */
    public function psetex(string $key, int $timeout, string $value): bool {}

    /**
     * @param string $key
     * @param string ...$members
     * @return int|false
     */
    public function sAdd(string $key, string ...$members): int|false {}

    /**
     * @param string $key
     * @param string ...$members
     * @return int
     */
    public function sRem(string $key, string ...$members): int {}

    /**
     * @param string $key
     * @return list<string>
     */
    public function sMembers(string $key): array {}

    /**
     * @param string $key
     * @return int
     */
    public function sCard(string $key): int {}
}
