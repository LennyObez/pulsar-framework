<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Exception\QueueException;

use function sprintf;

/**
 * Enforces rate limits on job processing using distributed cache locks.
 *
 * Acquires a per-queue lock before allowing the job to proceed. If the lock
 * cannot be acquired within the configured timeout, the job is rejected.
 *
 * Rate limiting is queue-scoped: each queue has its own lock resource.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Rate limiting middleware is an implementation detail of the queue system')]
final readonly class RateLimit implements JobMiddlewareInterface
{
    /**
     * @param LockInterface $lock          The distributed lock provider.
     * @param int           $ttlSeconds    Lock TTL in seconds (controls the rate window).
     * @param int           $timeoutMs     Maximum wait time in milliseconds (0 = fail immediately).
     */
    public function __construct(
        private LockInterface $lock,
        private int $ttlSeconds = 1,
        private int $timeoutMs = 0,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        $resource = sprintf('queue:rate:%s', $envelope->queue);

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
