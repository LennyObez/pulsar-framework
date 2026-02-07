<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Emitted when a persistent runtime worker is recycled.
 */
#[Internal]
final readonly class RuntimeWorkerRecyclePayload implements ConsoleEvent
{
    public function __construct(
        public string $reason,
        public int $requestCount,
        public int $memoryUsageMb,
        public int $uptimeSeconds,
    ) {}

    public function eventType(): EventType
    {
        return EventType::RuntimeWorkerRecycle;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'request_count' => $this->requestCount,
            'memory_usage_mb' => $this->memoryUsageMb,
            'uptime_seconds' => $this->uptimeSeconds,
        ];
    }
}
