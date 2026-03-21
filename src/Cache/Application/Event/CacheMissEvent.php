<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a cache key is not found (miss).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CacheMissEvent extends CacheEvent
{
    public function __construct(
        string $poolName,
        string $driverName,
        string $hashedKey,
        int $durationMicroseconds,
    ) {
        parent::__construct($poolName, $driverName, $hashedKey, $durationMicroseconds, 'miss');
    }
}
