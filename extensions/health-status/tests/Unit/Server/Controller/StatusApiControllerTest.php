<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\HealthStatus\Contracts\HealthCheckRunnerInterface;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;
use Pulsar\Extension\HealthStatus\Server\Controller\StatusApiController;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function json_decode;

#[CoversClass(StatusApiController::class)]
final class StatusApiControllerTest extends TestCase
{
    #[Test]
    public function currentReturnsJsonWith200(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->current();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function currentIncludesCacheControlHeader(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->current();

        self::assertStringContainsString('max-age=10', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function currentReturnsSnapshotStructure(): void
    {
        $snapshot = new HealthSnapshot(
            id: 'snap-struct',
            overallStatus: HealthStatus::Degraded,
            results: [
                ['name' => 'DB', 'status' => 'healthy', 'message' => 'Connected', 'latency_ms' => 3.2],
                ['name' => 'Cache', 'status' => 'degraded', 'message' => 'Slow', 'latency_ms' => 120.0],
            ],
            totalDurationMs: 123.2,
            capturedAt: new DateTimeImmutable('2024-03-27T12:00:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->current();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertSame('degraded', $body['overall_status']);
        /** @var list<array{name: string, status: string, latency_ms: float}> $results */
        $results = $body['results'];
        self::assertCount(2, $results);
        self::assertSame('DB', $results[0]['name']);
        self::assertSame('healthy', $results[0]['status']);
        self::assertSame(3.2, $results[0]['latency_ms']);
        self::assertSame(123.2, $body['total_duration_ms']);
    }

    #[Test]
    public function historyReturnsSnapshotsList(): void
    {
        $s1 = $this->createHealthySnapshot();
        $s2 = new HealthSnapshot(
            id: 'snap-hist-2',
            overallStatus: HealthStatus::Unhealthy,
            results: [['name' => 'DB', 'status' => 'unhealthy', 'message' => 'Timeout', 'latency_ms' => 500.0]],
            totalDurationMs: 500.0,
            capturedAt: new DateTimeImmutable('2024-03-27T11:55:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([$s1, $s2]);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $controller->history($request);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertSame(2, $body['count']);
        /** @var list<array{overall_status: string}> $snapshots */
        $snapshots = $body['snapshots'];
        self::assertCount(2, $snapshots);
        self::assertSame('healthy', $snapshots[0]['overall_status']);
        self::assertSame('unhealthy', $snapshots[1]['overall_status']);
    }

    #[Test]
    public function historyRespectsLimitParam(): void
    {
        $runner = $this->createStub(HealthCheckRunnerInterface::class);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['limit' => '10']);

        $response = $controller->history($request);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertSame(0, $body['count']);
    }

    #[Test]
    public function historyClampsTooLargeLimit(): void
    {
        $runner = $this->createStub(HealthCheckRunnerInterface::class);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['limit' => '9999']);

        $response = $controller->history($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function incidentsReturnsActiveAndRecent(): void
    {
        $active = new Incident(
            id: 'inc-1',
            checkName: 'DB',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Open,
            message: 'Connection pool exhausted',
            startedAt: new DateTimeImmutable('2024-03-27T11:00:00+00:00'),
        );
        $resolved = new Incident(
            id: 'inc-2',
            checkName: 'Cache',
            severity: IncidentSeverity::Minor,
            status: IncidentStatus::Resolved,
            message: 'Brief latency spike',
            startedAt: new DateTimeImmutable('2024-03-27T10:00:00+00:00'),
            resolvedAt: new DateTimeImmutable('2024-03-27T10:05:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('activeIncidents')->willReturn([$active]);
        $store->method('recentIncidents')->willReturn([$active, $resolved]);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->incidents();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertSame(1, $body['active_count']);
        /** @var list<array{check_name: string, severity: string, status: string}> $active */
        $active = $body['active'];
        /** @var list<array{status: string}> $recent */
        $recent = $body['recent'];
        self::assertCount(1, $active);
        self::assertCount(2, $recent);
        self::assertSame('DB', $active[0]['check_name']);
        self::assertSame('major', $active[0]['severity']);
        self::assertSame('open', $active[0]['status']);
        self::assertSame('resolved', $recent[1]['status']);
    }

    #[Test]
    public function currentStoresSnapshotAfterRun(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createMock(HealthHistoryStoreInterface::class);
        $store->expects(self::once())->method('storeSnapshot')->with($snapshot);

        $controller = new StatusApiController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $controller->current();
    }

    private function createHealthySnapshot(): HealthSnapshot
    {
        return new HealthSnapshot(
            id: 'snap-1',
            overallStatus: HealthStatus::Healthy,
            results: [['name' => 'Database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 2.5]],
            totalDurationMs: 2.5,
            capturedAt: new DateTimeImmutable('2024-03-27T12:00:00+00:00'),
        );
    }
}
