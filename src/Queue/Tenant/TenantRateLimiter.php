<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Middleware\JobMiddlewareInterface;

use function sprintf;

/**
 * Per-tenant job throughput limiter using distributed locks.
 *
 * Scopes rate limiting per tenant: each tenant on each queue has its own
 * lock resource (`queue:rate:{tenantId}:{queue}`), preventing noisy-neighbor
 * effects across tenants.
 */
#[Internal(reason: 'Tenant rate limiting middleware is an implementation detail of the queue system')]
final readonly class TenantRateLimiter implements JobMiddlewareInterface
{
    /**
     * @param LockInterface $lock       The distributed lock provider.
     * @param int           $ttlSeconds Lock TTL in seconds (controls the rate window).
     * @param int           $timeoutMs  Maximum wait time in milliseconds (0 = fail immediately).
     */
    public function __construct(
        private LockInterface $lock,
        private int $ttlSeconds = 1,
        private int $timeoutMs = 0,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        if ($envelope->tenantId === null) {
            return $next($envelope);
        }

        $resource = sprintf('queue:rate:%s:%s', $envelope->tenantId, $envelope->queue);

        try {
            $handle = $this->lock->acquire($resource, $this->ttlSeconds, $this->timeoutMs);
        } catch (LockAcquisitionException) {
            throw QueueException::rateLimitExceeded($envelope->queue, $envelope->jobClass);
        }

        try {
            return $next($envelope);
        } finally {
            $this->lock->release($handle);
        }
    }
}
