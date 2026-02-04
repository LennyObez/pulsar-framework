<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Periodic Fiber scheduler statistics.
 */
#[Internal]
final readonly class RuntimeSchedulerMetricPayload implements ConsoleEvent
{
    public function __construct(
        public int $activeFibers,
        public int $totalSpawned,
        public int $totalCompleted,
        public float $uptimeSeconds,
    ) {}

    public function eventType(): EventType
    {
        return EventType::RuntimeSchedulerMetric;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'active_fibers' => $this->activeFibers,
            'total_spawned' => $this->totalSpawned,
            'total_completed' => $this->totalCompleted,
            'uptime_seconds' => $this->uptimeSeconds,
        ];
    }
}
