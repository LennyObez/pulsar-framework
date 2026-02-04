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
        $this->expectExceptionMessage('health check not registered');

        $runner->run('nonexistent');
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
