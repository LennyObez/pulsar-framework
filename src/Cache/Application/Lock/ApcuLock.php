<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Random\Engine\Secure;
use Random\Randomizer;

use function apcu_add;
use function apcu_delete;
use function apcu_fetch;
use function apcu_store;
use function bin2hex;
use function hash_equals;
use function is_string;
use function microtime;
use function usleep;

/**
 * APCu-backed lock for single-server deployments.
 *
 * Provides best-effort mutual exclusion using APCu's apcu_add() for atomic
 * acquisition. Release and refresh operations are not fully atomic — APCu
 * does not provide a compare-and-swap (CAS) primitive, so a narrow TOCTOU
 * window exists between the token fetch and the subsequent delete/store.
 * This is acceptable for single-server use where the race window is
 * negligibly small. For distributed locking, prefer RedisLock.
 */
#[Internal]
final readonly class ApcuLock implements LockInterface
{
    private readonly Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $token = bin2hex($this->randomizer->getBytes(16));

            $added = apcu_add($resource, $token, $ttlSeconds);

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
        $success = false;

        /** @var string|false $currentToken */
        $currentToken = apcu_fetch($handle->resource, $success);

        if (!$success || !is_string($currentToken) || !hash_equals($currentToken, $handle->token)) {
            return false;
        }

        return apcu_delete($handle->resource);
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        $success = false;

        /** @var string|false $currentToken */
        $currentToken = apcu_fetch($handle->resource, $success);

        if (!$success || !is_string($currentToken) || !hash_equals($currentToken, $handle->token)) {
            return false;
        }

        // APCu does not provide a CAS primitive, so a narrow TOCTOU window
        // exists between the fetch above and this store. This is acceptable
        // for single-server deployments where the race is negligibly small.
        return apcu_store($handle->resource, $handle->token, $ttlSeconds);
    }
}
