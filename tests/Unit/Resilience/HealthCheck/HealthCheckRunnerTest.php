<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Exception\ResilienceException;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use RuntimeException;

#[CoversClass(HealthCheckRunner::class)]
final class HealthCheckRunnerTest extends TestCase
{
    #[Test]
    public function runAllRunsAllRegisteredChecks(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->createCheck('db', HealthCheckResult::healthy('db')));
        $runner->register($this->createCheck('cache', HealthCheckResult::healthy('cache')));

        $report = $runner->runAll();

        self::assertCount(2, $report->results);
        self::assertSame('db', $report->results[0]->name);
        self::assertSame('cache', $report->results[1]->name);
    }

    #[Test]
    public function overallStatusIsHealthyWhenAllPass(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->createCheck('db', HealthCheckResult::healthy('db')));
        $runner->register($this->createCheck('cache', HealthCheckResult::healthy('cache')));

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Healthy, $report->overallStatus);
    }

    #[Test]
    public function overallStatusIsUnhealthyWhenAnyCheckIsUnhealthy(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->createCheck('db', HealthCheckResult::healthy('db')));
        $runner->register($this->createCheck('cache', HealthCheckResult::unhealthy('cache', 'down')));
        $runner->register($this->createCheck('queue', HealthCheckResult::degraded('queue', 'slow')));

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Unhealthy, $report->overallStatus);
    }

    #[Test]
    public function overallStatusIsDegradedWhenDegradedButNoneUnhealthy(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->createCheck('db', HealthCheckResult::healthy('db')));
        $runner->register($this->createCheck('cache', HealthCheckResult::degraded('cache', 'slow response')));

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Degraded, $report->overallStatus);
    }

    #[Test]
    public function runSingleCheckByName(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->createCheck('db', HealthCheckResult::healthy('db', 'OK', 2.0)));

        $result = $runner->run('db');

        self::assertSame('db', $result->name);
        self::assertSame(HealthStatus::Healthy, $result->status);
    }

    #[Test]
    public function runThrowsForUnregisteredName(): void
    {
        $runner = new HealthCheckRunner();

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessageIsOrContains('health check not registered');

        $runner->run('nonexistent');
    }

    #[Test]
    public function runAllConvertsAThrowingCheckIntoAnUnhealthyResultAndContinues(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->createThrowingCheck('flaky'));
        $runner->register($this->createCheck('cache', HealthCheckResult::healthy('cache')));

        $report = $runner->runAll();

        // The throwing check must not abort the loop: both checks appear in the report.
        self::assertCount(2, $report->results);
        self::assertSame('flaky', $report->results[0]->name);
        self::assertSame(HealthStatus::Unhealthy, $report->results[0]->status);
        // The raw exception message must not leak into the public report.
        self::assertStringNotContainsString('secret connection string', $report->results[0]->message);
        self::assertStringContainsString('RuntimeException', $report->results[0]->message);

        // The second check still ran.
        self::assertSame('cache', $report->results[1]->name);
        self::assertSame(HealthStatus::Healthy, $report->results[1]->status);

        // A thrown failure degrades the overall status to unhealthy.
        self::assertSame(HealthStatus::Unhealthy, $report->overallStatus);
    }

    private function createThrowingCheck(string $name): HealthCheckInterface
    {
        return new class ($name) implements HealthCheckInterface {
            public function __construct(private readonly string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function check(): HealthCheckResult
            {
                throw new RuntimeException('secret connection string in message');
            }
        };
    }

    private function createCheck(string $name, HealthCheckResult $result): HealthCheckInterface
    {
        return new class ($name, $result) implements HealthCheckInterface {
            public function __construct(
                private readonly string $name,
                private readonly HealthCheckResult $result,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function check(): HealthCheckResult
            {
                return $this->result;
            }
        };
    }
}
