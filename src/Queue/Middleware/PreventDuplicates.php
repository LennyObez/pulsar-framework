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
 * Prevents duplicate job execution using idempotency keys and distributed locks.
 *
 * For jobs with a non-empty idempotency key, this middleware acquires a lock
 * keyed by the idempotency key. If the lock is already held (duplicate in
 * progress), the job is rejected.
 *
 * Jobs without an idempotency key pass through without dedup checks.
 */
#[Internal(reason: 'Deduplication middleware is an implementation detail of the queue system')]
final readonly class PreventDuplicates implements JobMiddlewareInterface
{
    /**
     * @param LockInterface $lock          The distributed lock provider.
     * @param int           $ttlSeconds    How long to hold the dedup lock (should exceed max job duration).
     */
    public function __construct(
        private LockInterface $lock,
        private int $ttlSeconds = 300,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        if ($envelope->idempotencyKey === '') {
            return $next($envelope);
        }

        $resource = sprintf('queue:dedup:%s', $envelope->idempotencyKey);

        try {
            $handle = $this->lock->acquire($resource, $this->ttlSeconds, 0);
        } catch (LockAcquisitionException) {
            throw QueueException::duplicateJob($envelope->id, $envelope->idempotencyKey);
        }

        try {
            return $next($envelope);
        } finally {
            $this->lock->release($handle);
        }
    }
}
