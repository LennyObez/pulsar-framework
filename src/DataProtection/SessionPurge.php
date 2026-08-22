<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;

/**
 * Purges expired session records via the session handler's gc() method.
 *
 * Works with FileHandler, DatabaseHandler, RedisHandler, and any
 * SessionHandlerInterface implementation that supports gc().
 */
#[Internal(reason: 'Reference implementation; use DataPurgeInterface for type hints')]
final readonly class SessionPurge implements DataPurgeInterface
{
    public function __construct(
        private SessionHandlerInterface $handler,
    ) {}

    #[Override]
    public function purge(RetentionPolicyInterface $policy): int
    {
        $maxLifetimeSeconds = $policy->retentionDays() * 86400;

        // A retention of 0 days means indefinite: do not purge
        if ($maxLifetimeSeconds === 0) {
            return 0;
        }

        $result = $this->handler->gc($maxLifetimeSeconds);

        // gc() returns int|false; false means no count available
        return $result !== false ? $result : 0;
    }

    #[Override]
    public function countExpired(RetentionPolicyInterface $policy): int
    {
        // Session handlers do not provide a count-only method.
        // Return 0 to indicate "unknown": callers should use purge() directly.
        return 0;
    }
}
