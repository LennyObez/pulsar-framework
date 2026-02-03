<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Emitted when a persistent runtime worker starts.
 */
#[Internal]
final readonly class RuntimeWorkerStartPayload implements ConsoleEvent
{
    public function __construct(
        public string $host,
        public int $port,
        public int $fiberConcurrency,
        public int $maxRequests,
        public int $memoryThresholdMb,
        public float $startedAt,
    ) {}

    public function eventType(): EventType
    {
        return EventType::RuntimeWorkerStart;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'fiber_concurrency' => $this->fiberConcurrency,
            'max_requests' => $this->maxRequests,
            'memory_threshold_mb' => $this->memoryThresholdMb,
            'started_at' => $this->startedAt,
        ];
    }
}
