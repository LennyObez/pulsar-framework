<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Queue\Envelope\JobEnvelope;

/**
 * Propagates request context through job envelopes.
 *
 * On dispatch: captures the current correlation ID, trace ID, span ID,
 * subject ID, and tenant ID from the RequestContextHolder and stamps
 * them into the envelope.
 *
 * On execution: restores the context into the RequestContextHolder so
 * downstream code can access tracing and identity information.
 */
#[Internal(reason: 'Context propagation middleware is an implementation detail of the queue system')]
final readonly class PropagateContext implements JobMiddlewareInterface
{
    public function __construct(
        private RequestContextHolder $contextHolder,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        $enriched = $this->injectContext($envelope);

        return $next($enriched);
    }

    /**
     * Inject current request context fields into the envelope if available.
     *
     * Fields already present on the envelope (from a previous dispatch) are
     * not overwritten: this allows context to survive re-dispatch scenarios.
     */
    private function injectContext(JobEnvelope $envelope): JobEnvelope
    {
        $requestContext = $this->contextHolder->tryGet();

        if ($requestContext === null) {
            return $envelope;
        }

        $correlationId = $envelope->correlationId !== ''
            ? $envelope->correlationId
            : $requestContext->correlationId->value;

        $subjectId = $envelope->subjectId ?? $requestContext->actor;
        $tenantId = $envelope->tenantId ?? $requestContext->tenantId;

        return new JobEnvelope(
            id: $envelope->id,
            jobClass: $envelope->jobClass,
            payload: $envelope->payload,
            queue: $envelope->queue,
            idempotencyKey: $envelope->idempotencyKey,
            correlationId: $correlationId,
            traceId: $envelope->traceId,
            spanId: $envelope->spanId,
            schemaVersion: $envelope->schemaVersion,
            keyId: $envelope->keyId,
            retryMaxAttempts: $envelope->retryMaxAttempts,
            retryBackoffStrategy: $envelope->retryBackoffStrategy,
            retryDelayMs: $envelope->retryDelayMs,
            tenantId: $tenantId,
            subjectId: $subjectId,
            batchId: $envelope->batchId,
            chainIndex: $envelope->chainIndex,
            attempt: $envelope->attempt,
            dispatchedAt: $envelope->dispatchedAt,
            encrypted: $envelope->encrypted,
            metadata: $envelope->metadata,
        );
    }
}
