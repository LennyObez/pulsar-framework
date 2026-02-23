<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Middleware\JobMiddlewareInterface;

use function sprintf;
use function str_starts_with;

/**
 * Routes failed jobs to tenant-specific dead letter queues.
 *
 * If the envelope has a tenantId and the queue is a DLQ (starts with "dlq"),
 * the queue name is rewritten to `dlq:tenant:{tenantId}` to maintain
 * tenant isolation in dead letter processing.
 */
#[Internal(reason: 'Tenant DLQ routing is an implementation detail of the queue system')]
final readonly class TenantDeadLetterRouter implements JobMiddlewareInterface
{
    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        $tenantId = $envelope->tenantId;

        if ($tenantId === null) {
            return $next($envelope);
        }

        if (! str_starts_with($envelope->queue, 'dlq')) {
            return $next($envelope);
        }

        $routed = new JobEnvelope(
            id: $envelope->id,
            jobClass: $envelope->jobClass,
            payload: $envelope->payload,
            queue: sprintf('dlq:tenant:%s', $tenantId),
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
