<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use Closure;
use Override;
use Pulsar\Api\Api;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Middleware\JobMiddlewareInterface;

use function sprintf;

/**
 * Routes jobs to tenant-specific queues.
 *
 * Rewrites the envelope queue name to `{baseQueue}:tenant:{tenantId}`, ensuring
 * tenant workloads are isolated at the queue level. Can be disabled for shared queues.
 */
#[Api(since: '1.0.0')]
final readonly class TenantQueueRouter implements JobMiddlewareInterface
{
    public function __construct(
        private bool $enabled = true,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        if (! $this->enabled || $envelope->tenantId === null) {
            return $next($envelope);
        }

        $routed = new JobEnvelope(
            id: $envelope->id,
            jobClass: $envelope->jobClass,
            payload: $envelope->payload,
            queue: sprintf('%s:tenant:%s', $envelope->queue, $envelope->tenantId),
            idempotencyKey: $envelope->idempotencyKey,
            correlationId: $envelope->correlationId,
            traceId: $envelope->traceId,
            spanId: $envelope->spanId,
            schemaVersion: $envelope->schemaVersion,
            keyId: $envelope->keyId,
            retryMaxAttempts: $envelope->retryMaxAttempts,
            retryBackoffStrategy: $envelope->retryBackoffStrategy,
            retryDelayMs: $envelope->retryDelayMs,
            tenantId: $envelope->tenantId,
            subjectId: $envelope->subjectId,
            batchId: $envelope->batchId,
            chainIndex: $envelope->chainIndex,
            attempt: $envelope->attempt,
            dispatchedAt: $envelope->dispatchedAt,
            encrypted: $envelope->encrypted,
            metadata: $envelope->metadata,
        );

        return $next($routed);
    }
}
