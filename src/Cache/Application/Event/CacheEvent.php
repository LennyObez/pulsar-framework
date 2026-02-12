<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Event;

use Pulsar\Api\Api;

/**
 * Base class for cache events.
 *
 * Keys are HMAC-hashed when a key hasher is configured via CacheEventEmitter,
 * passed as-is otherwise.
 */
#[Api(since: '1.0.0')]
abstract readonly class CacheEvent
{
    public function __construct(
        public string $poolName,
        public string $driverName,
        public string $hashedKey,
        public int $durationMicroseconds,
        public string $operationType,
    ) {}
}
