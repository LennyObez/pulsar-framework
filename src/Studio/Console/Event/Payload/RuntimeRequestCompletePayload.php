<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Emitted after each request in the persistent runtime.
 */
#[Internal]
final readonly class RuntimeRequestCompletePayload implements ConsoleEvent
{
    public function __construct(
        public string $method,
        public string $path,
        public int $statusCode,
        public float $durationMs,
        public int $memoryDeltaBytes,
    ) {}

    public function eventType(): EventType
    {
        return EventType::RuntimeRequestComplete;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'memory_delta_bytes' => $this->memoryDeltaBytes,
        ];
    }
}
