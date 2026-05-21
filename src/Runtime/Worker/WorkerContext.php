<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Worker;

use Pulsar\Api\Internal;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Runtime\RuntimeType;

use function getmypid;
use function memory_get_usage;
use function time;

/**
 * Tracks worker lifecycle state and provides recycling decisions.
 */
#[Internal]
final class WorkerContext
{
    private WorkerState $state = WorkerState::Stopped;
    private int $requestCount = 0;
    private int $startedAt = 0;

    public function __construct(
        private readonly RuntimeType $runtimeType,
    ) {}

    public function boot(): void
    {
        $this->state = WorkerState::Booting;
        $this->startedAt = time();
        $this->requestCount = 0;
    }

    public function ready(): void
    {
        $this->state = WorkerState::Ready;
    }

    public function beginRequest(): void
    {
        $this->state = WorkerState::Handling;
        $this->requestCount++;
    }

    public function endRequest(): void
    {
        $this->state = WorkerState::Ready;
    }

    public function drain(): void
    {
        $this->state = WorkerState::Draining;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function recycle(): void
    {
        $this->state = WorkerState::Recycling;
    }

    public function stop(): void
    {
        $this->state = WorkerState::Stopped;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function state(): WorkerState
    {
        return $this->state;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function requestCount(): int
    {
        return $this->requestCount;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function startedAt(): int
    {
        return $this->startedAt;
    }

    public function uptimeSeconds(): int
    {
        if ($this->startedAt === 0) {
            return 0;
        }

        return time() - $this->startedAt;
    }

    public function memoryUsageMb(): int
    {
        return (int) ((float) memory_get_usage(true) / 1024.0 / 1024.0);
    }

    public function info(): WorkerInfo
    {
        return new WorkerInfo(
            pid: (int) getmypid(),
            startedAt: $this->startedAt,
            requestCount: $this->requestCount,
            memoryUsageMb: $this->memoryUsageMb(),
            state: $this->state,
            runtimeType: $this->runtimeType,
        );
    }

    public function healthStatus(): WorkerHealthStatus
    {
        return match ($this->state) {
            WorkerState::Draining => WorkerHealthStatus::Draining,
            WorkerState::Recycling, WorkerState::Stopped => WorkerHealthStatus::ShuttingDown,
            default => WorkerHealthStatus::Healthy,
        };
    }

    /**
     * Check if the worker should recycle based on runtime configuration limits.
     *
     * @return string|null Reason for recycling, or null if no recycling needed
     */
    public function shouldRecycle(RuntimeConfig $config): ?string
    {
        if ($config->maxRequests > 0 && $this->requestCount >= $config->maxRequests) {
            return 'max_requests';
        }

        if ($config->memoryThresholdMb > 0 && $this->memoryUsageMb() >= $config->memoryThresholdMb) {
            return 'memory_threshold';
        }

        if ($config->timeLimitSeconds > 0 && $this->uptimeSeconds() >= $config->timeLimitSeconds) {
            return 'time_limit';
        }

        return null;
    }
}
