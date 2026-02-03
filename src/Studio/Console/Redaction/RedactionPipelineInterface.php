<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Redaction;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\EventType;

/**
 * Contract for applying redaction policies to event payloads.
 */
#[Internal]
interface RedactionPipelineInterface
{
    /**
     * Apply all applicable policies to a payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload, EventType $eventType): array;
}
