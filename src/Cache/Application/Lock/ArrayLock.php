<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function hash_equals;
use function microtime;
use function usleep;

/**
 * In-memory lock implementation for testing.
 */
#[Internal]
final class ArrayLock implements LockInterface
{
    /** @var array<string, array{token: string, expiresAt: float}> */
    private array $locks = [];

    private readonly Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        do {
            $this->evictExpired($resource);

            if (!isset($this->locks[$resource])) {
                $token = bin2hex($this->randomizer->getBytes(16));
                $this->locks[$resource] = [
                    'token' => $token,
                    'expiresAt' => microtime(true) + (float) $ttlSeconds,
                ];

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
        if (!isset($this->locks[$handle->resource])) {
            return false;
        }

        if (!hash_equals($this->locks[$handle->resource]['token'], $handle->token)) {
            return false;
        }

        unset($this->locks[$handle->resource]);

        return true;
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        $this->evictExpired($handle->resource);

        if (!isset($this->locks[$handle->resource])) {
            return false;
        }

        if (!hash_equals($this->locks[$handle->resource]['token'], $handle->token)) {
            return false;
        }

        $this->locks[$handle->resource]['expiresAt'] = microtime(true) + (float) $ttlSeconds;

        return true;
    }

    private function evictExpired(string $resource): void
    {
        if (isset($this->locks[$resource]) && $this->locks[$resource]['expiresAt'] <= microtime(true)) {
            unset($this->locks[$resource]);
        }
    }
}
