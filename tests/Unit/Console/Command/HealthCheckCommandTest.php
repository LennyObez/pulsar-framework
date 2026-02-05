<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\HealthCheckCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;

#[CoversClass(HealthCheckCommand::class)]
final class HealthCheckCommandTest extends TestCase
{
    #[Test]
    public function allHealthy(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->makeCheck('database', HealthCheckResult::healthy('database', 'OK', 5.0)));
        $runner->register($this->makeCheck('cache', HealthCheckResult::healthy('cache', 'OK', 2.0)));

        $command = new HealthCheckCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('health:check'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[OK]', $output->buffer);
        self::assertStringContainsString('database', $output->buffer);
        self::assertStringContainsString('All health checks passed', $output->buffer);
    }

    #[Test]
    public function unhealthyReturnsError(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->makeCheck(
            'database',
            HealthCheckResult::unhealthy('database', 'Connection refused', 0.0),
        ));

        $command = new HealthCheckCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('health:check'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[FAIL]', $output->buffer);
    }

    #[Test]
    public function degradedStatus(): void
    {
        $runner = new HealthCheckRunner();
        $runner->register($this->makeCheck(
            'database',
            HealthCheckResult::degraded('database', 'Slow response', 1500.0),
        ));

        $command = new HealthCheckCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('health:check'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[WARN]', $output->buffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $runner = new HealthCheckRunner();
        $command = new HealthCheckCommand($runner);

        self::assertSame('health:check', $command->name);
    }

    private function makeCheck(string $name, HealthCheckResult $result): HealthCheckInterface
    {
        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('check')->willReturn($result);

        return $check;
    }
}
