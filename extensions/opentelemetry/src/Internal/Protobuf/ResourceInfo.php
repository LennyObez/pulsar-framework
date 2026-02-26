<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Protobuf;

use Pulsar\Api\Internal;

/**
 * Resource information attached to all OTLP export requests.
 *
 * Represents the entity producing telemetry, identified by a set
 * of key-value attributes (e.g., service.name, service.version).
 */
#[Internal(reason: 'Resource metadata for OTLP export envelope')]
final readonly class ResourceInfo
{
    /**
     * @param array<string, scalar> $attributes Resource attributes
     */
    public function __construct(
        public array $attributes = [],
    ) {}
}
