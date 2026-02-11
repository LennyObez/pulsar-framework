<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Middleware\JobMiddlewareInterface;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\TenantContext;

/**
 * Captures tenant context on dispatch and restores it on execution.
 *
 * On dispatch: stamps the envelope with the current tenant ID from TenantContext.
 * On execution: restores the tenant scope from the envelope's tenantId, which
 * triggers full resource scoping (DB, cache, storage, crypto AAD).
 * After job completion: resets TenantScope to prevent cross-tenant state leakage.
 */
#[Internal(reason: 'Tenant job middleware is an implementation detail of the queue system')]
final readonly class TenantJobMiddleware implements JobMiddlewareInterface
{
    public function __construct(
        private TenantScope $scope,
        private TenantContext $context,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        $enriched = $this->captureContext($envelope);

        if ($enriched->tenantId === null) {
            return $next($enriched);
        }

        $tenantId = new TenantId($enriched->tenantId);
        $this->scope->enter($tenantId);

        try {
            return $next($enriched);
        } finally {
            $this->scope->exit();
            $this->scope->reset();
        }
    }

    /**
     * Capture the current tenant ID into the envelope if not already present.
     */
    private function captureContext(JobEnvelope $envelope): JobEnvelope
    {
        if ($envelope->tenantId !== null) {
            return $envelope;
        }

        if (! $this->context->isResolved()) {
            return $envelope;
        }

        return new JobEnvelope(
            id: $envelope->id,
            jobClass: $envelope->jobClass,
            payload: $envelope->payload,
            queue: $envelope->queue,
            idempotencyKey: $envelope->idempotencyKey,
            correlationId: $envelope->correlationId,
            traceId: $envelope->traceId,
            spanId: $envelope->spanId,
            schemaVersion: $envelope->schemaVersion,
            keyId: $envelope->keyId,
            retryMaxAttempts: $envelope->retryMaxAttempts,
            retryBackoffStrategy: $envelope->retryBackoffStrategy,
            retryDelayMs: $envelope->retryDelayMs,
            tenantId: $this->context->get()->id,
            subjectId: $envelope->subjectId,
            batchId: $envelope->batchId,
            chainIndex: $envelope->chainIndex,
            attempt: $envelope->attempt,
            dispatchedAt: $envelope->dispatchedAt,
            encrypted: $envelope->encrypted,
            metadata: $envelope->metadata,
        );
    }
}
