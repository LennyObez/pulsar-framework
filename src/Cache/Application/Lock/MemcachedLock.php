<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Memcached;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function hash_equals;
use function is_array;
use function is_string;
use function microtime;
use function usleep;

/**
 * Memcached-backed distributed lock.
 *
 * Provides best-effort mutual exclusion using Memcached's add() for atomic
 * acquisition. Release and refresh operations are not fully atomic due to
 * Memcached protocol limitations — a narrow TOCTOU window exists between
 * the token read and the subsequent delete/touch. For strong fencing
 * guarantees, prefer RedisLock which uses Lua scripts for atomic operations.
 */
#[Internal]
final readonly class MemcachedLock implements LockInterface
{
    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly Memcached $memcached,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $token = bin2hex($this->randomizer->getBytes(16));

            $added = $this->memcached->add($resource, $token, $ttlSeconds);

            if ($added) {
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
        /** @var string|false $currentToken */
        $currentToken = $this->memcached->get($handle->resource);

        if ($currentToken === false || !hash_equals($currentToken, $handle->token)) {
            return false;
        }

        return $this->memcached->delete($handle->resource);
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        /** @var array{value: string, cas: float}|false $extended */
        $extended = $this->memcached->get($handle->resource, null, Memcached::GET_EXTENDED);

        if (!is_array($extended) || !is_string($extended['value'] ?? null)) {
            return false;
        }

        if (!hash_equals($extended['value'], $handle->token)) {
            return false;
        }

        // Atomic check-and-set: only updates if the CAS token hasn't changed,
        // preventing TOCTOU races where another process acquires the lock between
        // our get and this write.
        return $this->memcached->cas(
            $extended['cas'],
            $handle->resource,
            $handle->token,
            $ttlSeconds,
        );
    }
}
