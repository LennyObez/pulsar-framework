<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\HealthResponse;
use Pulsar\Runtime\Worker\WorkerHealthStatus;
use Pulsar\Runtime\Worker\WorkerInfo;
use Pulsar\Runtime\Worker\WorkerState;

use function json_decode;
use function time;

#[CoversClass(HealthResponse::class)]
final class HealthResponseTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_properties(): void
    {
        $response = new HealthResponse(
            status: WorkerHealthStatus::Healthy,
            requestCount: 100,
            memoryUsageMb: 64,
            uptimeSeconds: 3600,
        );

        self::assertSame(WorkerHealthStatus::Healthy, $response->status);
        self::assertSame(100, $response->requestCount);
        self::assertSame(64, $response->memoryUsageMb);
        self::assertSame(3600, $response->uptimeSeconds);
    }

    #[Test]
    public function status_code_returns_200_for_healthy(): void
    {
        $response = new HealthResponse(
            status: WorkerHealthStatus::Healthy,
            requestCount: 0,
            memoryUsageMb: 0,
            uptimeSeconds: 0,
        );

        self::assertSame(200, $response->statusCode());
    }

    #[Test]
    public function status_code_returns_503_for_draining(): void
    {
        $response = new HealthResponse(
            status: WorkerHealthStatus::Draining,
            requestCount: 0,
            memoryUsageMb: 0,
            uptimeSeconds: 0,
        );

        self::assertSame(503, $response->statusCode());
    }

    #[Test]
    public function status_code_returns_503_for_shutting_down(): void
    {
        $response = new HealthResponse(
            status: WorkerHealthStatus::ShuttingDown,
            requestCount: 0,
            memoryUsageMb: 0,
            uptimeSeconds: 0,
        );

        self::assertSame(503, $response->statusCode());
    }

    #[Test]
    public function to_json_returns_valid_json(): void
    {
        $response = new HealthResponse(
            status: WorkerHealthStatus::Healthy,
            requestCount: 42,
            memoryUsageMb: 128,
            uptimeSeconds: 7200,
        );

        $json = $response->toJson();

        /** @var array{status: string, requests: int, memory_mb: int, uptime_s: int} $decoded */
        $decoded = json_decode($json, true);

        self::assertSame('healthy', $decoded['status']);
        self::assertSame(42, $decoded['requests']);
        self::assertSame(128, $decoded['memory_mb']);
        self::assertSame(7200, $decoded['uptime_s']);
    }

    #[Test]
    public function to_json_includes_draining_status(): void
    {
        $response = new HealthResponse(
            status: WorkerHealthStatus::Draining,
            requestCount: 1000,
            memoryUsageMb: 256,
            uptimeSeconds: 60,
        );

        $json = $response->toJson();

        /** @var array{status: string, requests: int, memory_mb: int, uptime_s: int} $decoded */
        $decoded = json_decode($json, true);

        self::assertSame('draining', $decoded['status']);
    }

    #[Test]
    public function from_worker_info_maps_ready_to_healthy(): void
    {
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: time() - 100,
            requestCount: 50,
            memoryUsageMb: 64,
            state: WorkerState::Ready,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        self::assertSame(WorkerHealthStatus::Healthy, $response->status);
        self::assertSame(50, $response->requestCount);
        self::assertSame(64, $response->memoryUsageMb);
    }

    #[Test]
    public function from_worker_info_maps_handling_to_healthy(): void
    {
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: time(),
            requestCount: 1,
            memoryUsageMb: 32,
            state: WorkerState::Handling,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        self::assertSame(WorkerHealthStatus::Healthy, $response->status);
    }

    #[Test]
    public function from_worker_info_maps_booting_to_healthy(): void
    {
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: time(),
            requestCount: 0,
            memoryUsageMb: 16,
            state: WorkerState::Booting,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        self::assertSame(WorkerHealthStatus::Healthy, $response->status);
    }

    #[Test]
    public function from_worker_info_maps_draining_to_draining(): void
    {
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: time() - 300,
            requestCount: 200,
            memoryUsageMb: 128,
            state: WorkerState::Draining,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        self::assertSame(WorkerHealthStatus::Draining, $response->status);
    }

    #[Test]
    public function from_worker_info_maps_recycling_to_shutting_down(): void
    {
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: time() - 600,
            requestCount: 500,
            memoryUsageMb: 200,
            state: WorkerState::Recycling,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        self::assertSame(WorkerHealthStatus::ShuttingDown, $response->status);
    }

    #[Test]
    public function from_worker_info_maps_stopped_to_shutting_down(): void
    {
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: time() - 10,
            requestCount: 0,
            memoryUsageMb: 16,
            state: WorkerState::Stopped,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        self::assertSame(WorkerHealthStatus::ShuttingDown, $response->status);
    }

    #[Test]
    public function from_worker_info_calculates_uptime(): void
    {
        $startedAt = time() - 120;
        $info = new WorkerInfo(
            pid: 1234,
            startedAt: $startedAt,
            requestCount: 10,
            memoryUsageMb: 32,
            state: WorkerState::Ready,
            runtimeType: RuntimeType::Persistent,
        );

        $response = HealthResponse::fromWorkerInfo($info);

        // Allow 1 second tolerance for test execution time
        self::assertGreaterThanOrEqual(119, $response->uptimeSeconds);
        self::assertLessThanOrEqual(121, $response->uptimeSeconds);
    }
}
