<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\BenchmarkProfilePayload;
use Pulsar\Extension\Studio\Console\Event\Payload\BenchmarkRunPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\IntegrityCheckPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeLeakWarningPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeRequestCompletePayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeSchedulerMetricPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeWorkerRecyclePayload;
use Pulsar\Extension\Studio\Console\Event\Payload\RuntimeWorkerStartPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\SupervisorPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\TenancyPayload;

#[CoversClass(BenchmarkProfilePayload::class)]
#[CoversClass(BenchmarkRunPayload::class)]
#[CoversClass(IntegrityCheckPayload::class)]
#[CoversClass(RuntimeLeakWarningPayload::class)]
#[CoversClass(RuntimeRequestCompletePayload::class)]
#[CoversClass(RuntimeSchedulerMetricPayload::class)]
#[CoversClass(RuntimeWorkerRecyclePayload::class)]
#[CoversClass(RuntimeWorkerStartPayload::class)]
#[CoversClass(SupervisorPayload::class)]
#[CoversClass(TenancyPayload::class)]
final class EventPayloadsTest extends TestCase
{
    // --- EventType ---

    #[Test]
    public function eventTypeCaseCount(): void
    {
        self::assertCount(25, EventType::cases());
    }

    #[Test]
    public function eventTypeKeyValues(): void
    {
        self::assertSame('http.request', EventType::HttpRequest->value);
        self::assertSame('db.query', EventType::DatabaseQuery->value);
        self::assertSame('exception', EventType::Exception->value);
        self::assertSame('benchmark.profile', EventType::BenchmarkProfile->value);
        self::assertSame('runtime.worker_start', EventType::RuntimeWorkerStart->value);
    }

    // --- EventVersion ---

    #[Test]
    public function eventVersionV1(): void
    {
        self::assertSame(1, EventVersion::V1->value);
        self::assertCount(1, EventVersion::cases());
    }

    // --- BenchmarkProfilePayload ---

    #[Test]
    public function benchmarkProfilePayloadConstruction(): void
    {
        $payload = new BenchmarkProfilePayload(
            runId: 'run-1',
            profileName: 'cold-boot',
            profileDescription: 'Cold boot profile',
            bootUs: 5000,
            warmBootUs: 2000,
            p50Us: 100,
            p95Us: 500,
            rps: 10000,
            peakRssKb: 32768,
            memoryUsageKb: 16384,
            opcacheMemoryKb: 4096,
            iterations: 1000,
            jitEnabled: true,
            jitMode: 'tracing',
            preloadEnabled: false,
            optimizeEnabled: true,
        );

        self::assertSame(EventType::BenchmarkProfile, $payload->eventType());
        self::assertSame(EventVersion::V1, $payload->schemaVersion());

        $array = $payload->toArray();
        self::assertSame('run-1', $array['run_id']);
        self::assertSame('cold-boot', $array['profile_name']);
        self::assertSame(5000, $array['boot_us']);
        self::assertSame(4096, $array['opcache_memory_kb']);
        self::assertTrue($array['jit_enabled']);
        self::assertCount(16, $array);
    }

    // --- BenchmarkRunPayload ---

    #[Test]
    public function benchmarkRunPayloadConstruction(): void
    {
        $payload = new BenchmarkRunPayload(
            runId: 'run-2',
            phpVersion: '8.5.0',
            phpSapi: 'cli',
            osPlatform: 'linux',
            osArch: 'x86_64',
            profileCount: 5,
            successCount: 4,
            failureCount: 1,
            skippedCount: 0,
            totalDurationMs: 15000.5,
            profileNames: ['cold-boot', 'warm-boot', 'route-match'],
        );

        self::assertSame(EventType::BenchmarkRun, $payload->eventType());
        self::assertSame(EventVersion::V1, $payload->schemaVersion());

        $array = $payload->toArray();
        self::assertSame('run-2', $array['run_id']);
        self::assertSame('8.5.0', $array['php_version']);
        self::assertSame(5, $array['profile_count']);
        self::assertSame(15000.5, $array['total_duration_ms']);
        self::assertIsArray($array['profile_names']);
        self::assertCount(3, $array['profile_names']);
    }

    // --- IntegrityCheckPayload ---

    #[Test]
    public function integrityCheckPayloadConstruction(): void
    {
        $payload = new IntegrityCheckPayload(
            passed: false,
            verified: 500,
            modified: 2,
            missing: 1,
            added: 3,
            checkedAt: 1709856000,
        );

        self::assertSame(EventType::IntegrityCheck, $payload->eventType());

        $array = $payload->toArray();
        self::assertFalse($array['passed']);
        self::assertSame(500, $array['verified']);
        self::assertSame(2, $array['modified']);
        self::assertSame(1, $array['missing']);
        self::assertSame(3, $array['added']);
    }

    // --- RuntimeLeakWarningPayload ---

    #[Test]
    public function runtimeLeakWarningPayloadConstruction(): void
    {
        $payload = new RuntimeLeakWarningPayload(
            warnings: ['Memory grew by 50MB in 10 requests', 'Unreleased DB connections'],
            memoryDeltaBytes: 52428800,
            requestNumber: 150,
        );

        self::assertSame(EventType::RuntimeLeakWarning, $payload->eventType());

        $array = $payload->toArray();
        self::assertIsArray($array['warnings']);
        self::assertCount(2, $array['warnings']);
        self::assertSame(52428800, $array['memory_delta_bytes']);
        self::assertSame(150, $array['request_number']);
    }

    // --- RuntimeRequestCompletePayload ---

    #[Test]
    public function runtimeRequestCompletePayloadConstruction(): void
    {
        $payload = new RuntimeRequestCompletePayload(
            method: 'GET',
            path: '/api/users',
            statusCode: 200,
            durationMs: 12.5,
            memoryDeltaBytes: 1024,
        );

        self::assertSame(EventType::RuntimeRequestComplete, $payload->eventType());

        $array = $payload->toArray();
        self::assertSame('GET', $array['method']);
        self::assertSame('/api/users', $array['path']);
        self::assertSame(200, $array['status_code']);
        self::assertSame(12.5, $array['duration_ms']);
    }

    // --- RuntimeSchedulerMetricPayload ---

    #[Test]
    public function runtimeSchedulerMetricPayloadConstruction(): void
    {
        $payload = new RuntimeSchedulerMetricPayload(
            activeFibers: 5,
            totalSpawned: 100,
            totalCompleted: 95,
            uptimeSeconds: 3600.0,
        );

        self::assertSame(EventType::RuntimeSchedulerMetric, $payload->eventType());

        $array = $payload->toArray();
        self::assertSame(5, $array['active_fibers']);
        self::assertSame(100, $array['total_spawned']);
        self::assertSame(95, $array['total_completed']);
        self::assertSame(3600.0, $array['uptime_seconds']);
    }

    // --- RuntimeWorkerRecyclePayload ---

    #[Test]
    public function runtimeWorkerRecyclePayloadConstruction(): void
    {
        $payload = new RuntimeWorkerRecyclePayload(
            reason: 'max_requests_reached',
            requestCount: 1000,
            memoryUsageMb: 256,
            uptimeSeconds: 7200,
        );

        self::assertSame(EventType::RuntimeWorkerRecycle, $payload->eventType());

        $array = $payload->toArray();
        self::assertSame('max_requests_reached', $array['reason']);
        self::assertSame(1000, $array['request_count']);
    }

    // --- RuntimeWorkerStartPayload ---

    #[Test]
    public function runtimeWorkerStartPayloadConstruction(): void
    {
        $payload = new RuntimeWorkerStartPayload(
            host: '0.0.0.0',
            port: 8080,
            fiberConcurrency: 128,
            maxRequests: 10000,
            memoryThresholdMb: 512,
            startedAt: 1709856000.5,
        );

        self::assertSame(EventType::RuntimeWorkerStart, $payload->eventType());

        $array = $payload->toArray();
        self::assertSame('0.0.0.0', $array['host']);
        self::assertSame(8080, $array['port']);
        self::assertSame(128, $array['fiber_concurrency']);
    }

    // --- SupervisorPayload ---

    #[Test]
    public function supervisorPayloadConstruction(): void
    {
        $payload = new SupervisorPayload(
            type: 'recycle',
            action: 'worker_restart',
            success: true,
            details: ['worker_id' => 3, 'reason' => 'memory_limit'],
            performedAt: 1709856000,
        );

        self::assertSame(EventType::Heartbeat, $payload->eventType());

        $array = $payload->toArray();
        self::assertSame('recycle', $array['type']);
        self::assertSame('worker_restart', $array['action']);
        self::assertTrue($array['success']);
        self::assertIsArray($array['details']);
        self::assertSame(3, $array['details']['worker_id']);
    }

    // --- TenancyPayload ---

    #[Test]
    public function tenancyPayloadConstruction(): void
    {
        $payload = new TenancyPayload(
            tenantHash: 'sha256:abc123',
            resolverStrategy: 'subdomain',
            resolved: true,
        );

        self::assertSame(EventType::Heartbeat, $payload->eventType());

        $array = $payload->toArray();
        self::assertSame('sha256:abc123', $array['tenant_hash']);
        self::assertSame('subdomain', $array['resolver_strategy']);
        self::assertTrue($array['resolved']);
    }
}
