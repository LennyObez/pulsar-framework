<?php

declare(strict_types=1);

namespace Pulsar\Observability\Context;

use Pulsar\Api\Api;

/**
 * Immutable correlation context DTO.
 *
 * Carries correlation identity for tracing events across subsystems.
 * Note: tenantHash is deliberately excluded; tenant identity is resolved
 * exclusively by StudioManager::ingest() via TenantContext::tryGet().
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CorrelationContext
{
    public function __construct(
        public ?string $requestId = null,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $jobId = null,
    ) {}
}
