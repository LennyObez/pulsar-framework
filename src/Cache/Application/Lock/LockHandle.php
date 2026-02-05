<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Api;

/**
 * Fencing token carrier for an acquired lock.
 */
#[Api(since: '1.0.0')]
final readonly class LockHandle
{
    public function __construct(
        public string $resource,
        public string $token,
        public float $acquiredAt,
        public int $ttlSeconds,
    ) {}
}
