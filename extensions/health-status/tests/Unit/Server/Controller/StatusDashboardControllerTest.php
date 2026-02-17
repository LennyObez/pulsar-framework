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
use Pulsar\Extension\HealthStatus\Server\Controller\StatusDashboardController;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(StatusDashboardController::class)]
final class StatusDashboardControllerTest extends TestCase
{
    #[Test]
    public function invokeReturnsHtmlResponseWith200(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([$snapshot]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function invokeRendersFullHtmlDocument(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('System Status', $body);
        self::assertStringContainsString('pulsar-ui.css', $body);
        self::assertStringContainsString('health-status.css', $body);
    }

    #[Test]
    public function invokeRendersCheckCards(): void
    {
        $snapshot = new HealthSnapshot(
            id: 'snap-2',
            overallStatus: HealthStatus::Degraded,
            results: [
                ['name' => 'Database', 'status' => 'healthy', 'message' => 'OK', 'latency_ms' => 2.5],
                ['name' => 'Cache', 'status' => 'degraded', 'message' => 'Slow response', 'latency_ms' => 150.0],
            ],
            totalDurationMs: 152.5,
            capturedAt: new DateTimeImmutable('2024-03-27T12:00:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('Database', $body);
        self::assertStringContainsString('Cache', $body);
        self::assertStringContainsString('check-card--healthy', $body);
        self::assertStringContainsString('check-card--degraded', $body);
    }

    #[Test]
    public function invokeRendersActiveIncidents(): void
    {
        $snapshot = $this->createHealthySnapshot();
        $incident = new Incident(
            id: 'inc-1',
            checkName: 'Database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Open,
            message: 'Connection pool exhausted',
            startedAt: new DateTimeImmutable('2024-03-27T11:00:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);
        $store->method('activeIncidents')->willReturn([$incident]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('incident-banner--major', $body);
        self::assertStringContainsString('Connection pool exhausted', $body);
    }

    #[Test]
    public function invokeRendersTimelineHistory(): void
    {
        $snapshot1 = $this->createHealthySnapshot();
        $snapshot2 = new HealthSnapshot(
            id: 'snap-3',
            overallStatus: HealthStatus::Unhealthy,
            results: [['name' => 'DB', 'status' => 'unhealthy', 'message' => 'Timeout', 'latency_ms' => 500.0]],
            totalDurationMs: 500.0,
            capturedAt: new DateTimeImmutable('2024-03-27T11:55:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot1);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([$snapshot1, $snapshot2]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('timeline__row', $body);
        self::assertStringContainsString('status-badge--unhealthy', $body);
    }

    #[Test]
    public function invokeStoresSnapshotAfterRun(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createMock(HealthHistoryStoreInterface::class);
        $store->expects(self::once())->method('storeSnapshot')->with($snapshot);
        $store->method('recentSnapshots')->willReturn([]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $controller($request);
    }

    #[Test]
    public function invokeIncludesAutoRefreshMeta(): void
    {
        $snapshot = $this->createHealthySnapshot();

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('http-equiv="refresh"', $body);
        self::assertStringContainsString('content="30"', $body);
    }

    #[Test]
    public function invokeEscapesCheckNameInOutput(): void
    {
        $snapshot = new HealthSnapshot(
            id: 'snap-xss',
            overallStatus: HealthStatus::Healthy,
            results: [['name' => '<script>alert(1)</script>', 'status' => 'healthy', 'message' => '', 'latency_ms' => 1.0]],
            totalDurationMs: 1.0,
            capturedAt: new DateTimeImmutable('2024-03-27T12:00:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('run')->willReturn($snapshot);

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('recentSnapshots')->willReturn([]);
        $store->method('activeIncidents')->willReturn([]);

        $controller = new StatusDashboardController($store, $runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
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
