<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Random\Engine\Secure;
use Random\Randomizer;
use Redis;

use function bin2hex;
use function microtime;
use function usleep;

/**
 * Redis-backed distributed lock with Lua-based atomic operations.
 */
#[Internal]
final class RedisLock implements LockInterface
{
    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("DEL", KEYS[1])
        else
            return 0
        end
        LUA;

    private const string REFRESH_SCRIPT = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("PEXPIRE", KEYS[1], ARGV[2])
        else
            return 0
        end
        LUA;

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly Redis $redis,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);
        $ttlMs = $ttlSeconds * 1000;

        do {
            $token = bin2hex($this->randomizer->getBytes(16));

            /** @var bool $result */
            $result = $this->redis->set(
                $resource,
                $token,
                ['NX', 'PX' => $ttlMs],
            );

            if ($result) {
                return new LockHandle(
                    resource: $resource,
                    token: $token,
                    acquiredAt: microtime(true),
                    ttlSeconds: $ttlSeconds,
                );
            }

            if ($timeoutMs === 0) {
                throw LockAcquisitionException::timeout($resource, $timeoutMs);
            }

            usleep(10_000);
        } while (hrtime(true) < $deadlineNs);

        throw LockAcquisitionException::timeout($resource, $timeoutMs);
    }

    public function release(LockHandle $handle): bool
    {
        /** @var int $result */
        $result = $this->redis->eval(
            self::RELEASE_SCRIPT,
            [$handle->resource, $handle->token],
            1,
        );

        return $result === 1;
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        $ttlMs = $ttlSeconds * 1000;

        /** @var int $result */
        $result = $this->redis->eval(
            self::REFRESH_SCRIPT,
            [$handle->resource, $handle->token, (string) $ttlMs],
            1,
        );

        return $result === 1;
    }
}
