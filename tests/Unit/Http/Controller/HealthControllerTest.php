<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Controller\HealthController;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(HealthController::class)]
final class HealthControllerTest extends TestCase
{
    #[Test]
    public function returns200JsonWhenAllChecksHealthy(): void
    {
        $results = [
            HealthCheckResult::healthy('cache', 'OK', 1.5),
            HealthCheckResult::healthy('disk', '500 MB free', 0.3),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: $results,
            generatedAt: new DateTimeImmutable('2026-03-09T12:00:00+00:00'),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        self::assertSame('healthy', $body['status']);
        self::assertArrayHasKey('checks', $body);

        $checks = $body['checks'];
        self::assertIsArray($checks);
        self::assertArrayHasKey('cache', $checks);
        self::assertArrayHasKey('disk', $checks);

        $cacheCheck = $checks['cache'];
        self::assertIsArray($cacheCheck);
        self::assertSame('healthy', $cacheCheck['status']);

        $diskCheck = $checks['disk'];
        self::assertIsArray($diskCheck);
        self::assertSame('healthy', $diskCheck['status']);

        self::assertArrayHasKey('timestamp', $body);
    }

    #[Test]
    public function returns503JsonWhenAnyCheckUnhealthy(): void
    {
        $results = [
            HealthCheckResult::healthy('cache', 'OK', 1.0),
            HealthCheckResult::unhealthy('database', 'Connection refused', 50.0),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Unhealthy,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('unhealthy', $body['status']);

        $checks = $body['checks'];
        self::assertIsArray($checks);

        $dbCheck = $checks['database'];
        self::assertIsArray($dbCheck);
        self::assertSame('unhealthy', $dbCheck['status']);
        self::assertArrayHasKey('message', $dbCheck);
        self::assertIsString($dbCheck['message']);
        self::assertStringContainsString('Connection refused', $dbCheck['message']);
    }

    #[Test]
    public function returns503JsonWhenAnyCheckDegraded(): void
    {
        $results = [
            HealthCheckResult::degraded('cache', 'Slow response', 600.0),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Degraded,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('degraded', $body['status']);
    }

    #[Test]
    public function responseIncludesCheckDetails(): void
    {
        $results = [
            HealthCheckResult::healthy('cache', 'Cache responded in 2.5ms', 2.5),
        ];

        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: $results,
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        $checks = $body['checks'];
        self::assertIsArray($checks);

        $cacheCheck = $checks['cache'];
        self::assertIsArray($cacheCheck);
        self::assertSame('Cache responded in 2.5ms', $cacheCheck['message']);
        self::assertSame(2.5, $cacheCheck['latency_ms']);
    }

    #[Test]
    public function responseIncludesTimestamp(): void
    {
        $generatedAt = new DateTimeImmutable('2026-03-09T15:30:00+00:00');
        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [],
            generatedAt: $generatedAt,
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $controller = new HealthController($runner);
        $response = $controller();

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        self::assertSame('2026-03-09T15:30:00+00:00', $body['timestamp']);
    }
}
