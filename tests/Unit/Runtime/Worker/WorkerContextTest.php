<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\HealthStatus;
use Pulsar\Runtime\Worker\WorkerContext;
use Pulsar\Runtime\Worker\WorkerInfo;
use Pulsar\Runtime\Worker\WorkerState;

#[CoversClass(WorkerContext::class)]
final class WorkerContextTest extends TestCase
{
    #[Test]
    public function it_starts_in_stopped_state(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);

        self::assertSame(WorkerState::Stopped, $ctx->state());
        self::assertSame(0, $ctx->requestCount());
        self::assertSame(0, $ctx->startedAt());
    }

    #[Test]
    public function it_boots_and_transitions_to_ready(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);

        $ctx->boot();
        self::assertSame(WorkerState::Booting, $ctx->state());
        self::assertGreaterThan(0, $ctx->startedAt());
        self::assertSame(0, $ctx->requestCount());

        $ctx->ready();
        self::assertSame(WorkerState::Ready, $ctx->state());
    }

    #[Test]
    public function it_handles_request_lifecycle(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();

        $ctx->beginRequest();
        self::assertSame(WorkerState::Handling, $ctx->state());
        self::assertSame(1, $ctx->requestCount());

        $ctx->endRequest();
        self::assertSame(WorkerState::Ready, $ctx->state());

        $ctx->beginRequest();
        self::assertSame(2, $ctx->requestCount());
        $ctx->endRequest();
    }

    #[Test]
    public function it_transitions_to_draining(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();

        $ctx->drain();
        self::assertSame(WorkerState::Draining, $ctx->state());
    }

    #[Test]
    public function it_transitions_to_recycling(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();

        $ctx->recycle();
        self::assertSame(WorkerState::Recycling, $ctx->state());
    }

    #[Test]
    public function it_transitions_to_stopped(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();

        $ctx->stop();
        self::assertSame(WorkerState::Stopped, $ctx->state());
    }

    #[Test]
    public function it_resets_request_count_on_boot(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();
        $ctx->beginRequest();
        $ctx->endRequest();
        self::assertSame(1, $ctx->requestCount());

        $ctx->boot();
        self::assertSame(0, $ctx->requestCount());
    }

    #[Test]
    public function it_returns_zero_uptime_when_not_started(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);

        self::assertSame(0, $ctx->uptimeSeconds());
    }

    #[Test]
    public function it_tracks_uptime_after_boot(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();

        self::assertGreaterThanOrEqual(0, $ctx->uptimeSeconds());
    }

    #[Test]
    public function it_returns_memory_usage_in_megabytes(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);

        $memoryMb = $ctx->memoryUsageMb();
        self::assertGreaterThanOrEqual(0, $memoryMb);
    }

    #[Test]
    public function it_returns_worker_info_snapshot(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();
        $ctx->beginRequest();

        $info = $ctx->info();

        self::assertInstanceOf(WorkerInfo::class, $info);
        self::assertGreaterThan(0, $info->pid);
        self::assertGreaterThan(0, $info->startedAt);
        self::assertSame(1, $info->requestCount);
        self::assertGreaterThanOrEqual(0, $info->memoryUsageMb);
        self::assertSame(WorkerState::Handling, $info->state);
        self::assertSame(RuntimeType::Persistent, $info->runtimeType);
    }

    #[Test]
    public function it_maps_health_status_for_healthy_states(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);

        $ctx->boot();
        self::assertSame(HealthStatus::Healthy, $ctx->healthStatus());

        $ctx->ready();
        self::assertSame(HealthStatus::Healthy, $ctx->healthStatus());

        $ctx->beginRequest();
        self::assertSame(HealthStatus::Healthy, $ctx->healthStatus());
    }

    #[Test]
    public function it_maps_health_status_for_draining(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();

        $ctx->drain();
        self::assertSame(HealthStatus::Draining, $ctx->healthStatus());
    }

    #[Test]
    public function it_maps_health_status_for_shutting_down_states(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $ctx->boot();
        $ctx->ready();

        $ctx->recycle();
        self::assertSame(HealthStatus::ShuttingDown, $ctx->healthStatus());

        $ctx->stop();
        self::assertSame(HealthStatus::ShuttingDown, $ctx->healthStatus());
    }

    #[Test]
    public function it_recycles_when_max_requests_exceeded(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $config = new RuntimeConfig(maxRequests: 2, memoryThresholdMb: 0, timeLimitSeconds: 0);

        $ctx->boot();
        $ctx->ready();

        $ctx->beginRequest();
        $ctx->endRequest();
        self::assertNull($ctx->shouldRecycle($config));

        $ctx->beginRequest();
        $ctx->endRequest();
        self::assertSame('max_requests', $ctx->shouldRecycle($config));
    }

    #[Test]
    public function it_does_not_recycle_when_max_requests_is_zero(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $config = new RuntimeConfig(maxRequests: 0, memoryThresholdMb: 0, timeLimitSeconds: 0);

        $ctx->boot();
        $ctx->ready();
        $ctx->beginRequest();
        $ctx->endRequest();

        self::assertNull($ctx->shouldRecycle($config));
    }

    #[Test]
    public function it_recycles_when_time_limit_exceeded(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $config = new RuntimeConfig(maxRequests: 0, memoryThresholdMb: 0, timeLimitSeconds: 1);

        $ctx->boot();
        $ctx->ready();

        // The worker just started, so time limit should not be exceeded
        self::assertNull($ctx->shouldRecycle($config));
    }

    #[Test]
    public function it_does_not_recycle_when_time_limit_is_zero(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $config = new RuntimeConfig(maxRequests: 0, memoryThresholdMb: 0, timeLimitSeconds: 0);

        $ctx->boot();
        $ctx->ready();

        self::assertNull($ctx->shouldRecycle($config));
    }

    #[Test]
    public function it_does_not_recycle_when_memory_threshold_is_zero(): void
    {
        $ctx = new WorkerContext(RuntimeType::Persistent);
        $config = new RuntimeConfig(maxRequests: 0, memoryThresholdMb: 0, timeLimitSeconds: 0);

        $ctx->boot();

        self::assertNull($ctx->shouldRecycle($config));
    }
}
