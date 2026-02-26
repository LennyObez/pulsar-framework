<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Worker;

use Pulsar\Api\Internal;

use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Health endpoint response data.
 */
#[Internal]
final readonly class HealthResponse
{
    public function __construct(
        public HealthStatus $status,
        public int $requestCount,
        public int $memoryUsageMb,
        public int $uptimeSeconds,
    ) {}

    public function statusCode(): int
    {
        return $this->status === HealthStatus::Healthy ? 200 : 503;
    }

    public function toJson(): string
    {
        return json_encode([
            'status' => $this->status->value,
            'requests' => $this->requestCount,
            'memory_mb' => $this->memoryUsageMb,
            'uptime_s' => $this->uptimeSeconds,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromWorkerInfo(WorkerInfo $info): self
    {
        $healthStatus = match ($info->state) {
            WorkerState::Draining => HealthStatus::Draining,
            WorkerState::Recycling, WorkerState::Stopped => HealthStatus::ShuttingDown,
            default => HealthStatus::Healthy,
        };

        return new self(
            status: $healthStatus,
            requestCount: $info->requestCount,
            memoryUsageMb: $info->memoryUsageMb,
            uptimeSeconds: time() - $info->startedAt,
        );
    }
}
