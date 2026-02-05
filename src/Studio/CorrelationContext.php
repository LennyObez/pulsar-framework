<?php

declare(strict_types=1);

namespace Pulsar\Studio;

use Pulsar\Api\Internal;

/**
 * Immutable correlation context DTO.
 *
 * Carries correlation identity for tracing events across subsystems.
 * Note: tenantHash is deliberately excluded — tenant identity is resolved
 * exclusively by StudioManager::ingest() via TenantContext::tryGet().
 */
#[Internal]
final readonly class CorrelationContext
{
    public function __construct(
        public ?string $requestId = null,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $jobId = null,
    ) {}
}
