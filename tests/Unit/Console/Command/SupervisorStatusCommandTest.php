<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Console\Command\SupervisorStatusCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(SupervisorStatusCommand::class)]
final class SupervisorStatusCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $config = new SupervisorConfig();
        $command = new SupervisorStatusCommand($config);

        self::assertSame('supervisor:status', $command->name);
    }

    #[Test]
    public function enabledTextOutput(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 5000,
            recycleMemoryThresholdMb: 128,
            recycleTimeLimitSeconds: 3600,
            stuckJobTimeoutSeconds: 120,
            stuckJobCheckIntervalSeconds: 30,
            stuckJobMoveToDeadLetter: true,
        );

        $command = new SupervisorStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('supervisor:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Status: enabled', $output->buffer);
        self::assertStringContainsString('Worker Recycle Policy:', $output->buffer);
        self::assertStringContainsString('Max requests:       5000', $output->buffer);
        self::assertStringContainsString('Memory threshold:   128 MB', $output->buffer);
        self::assertStringContainsString('Time limit:         3600 seconds', $output->buffer);
        self::assertStringContainsString('Stuck Job Policy:', $output->buffer);
        self::assertStringContainsString('Timeout:            120 seconds', $output->buffer);
        self::assertStringContainsString('Check interval:     30 seconds', $output->buffer);
        self::assertStringContainsString('Dead-letter on stuck: yes', $output->buffer);
    }

    #[Test]
    public function disabledTextOutput(): void
    {
        $config = new SupervisorConfig(enabled: false);

        $command = new SupervisorStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('supervisor:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Status: disabled', $output->buffer);
        self::assertStringContainsString('Supervisor is disabled', $output->buffer);
        self::assertStringNotContainsString('Worker Recycle Policy:', $output->buffer);
        self::assertStringNotContainsString('Stuck Job Policy:', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 256,
            recycleTimeLimitSeconds: 7200,
            stuckJobTimeoutSeconds: 300,
            stuckJobCheckIntervalSeconds: 60,
            stuckJobMoveToDeadLetter: false,
        );

        $command = new SupervisorStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('supervisor:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $data */
        $data = json_decode(trim($output->buffer), true);
        self::assertIsArray($data);
        self::assertTrue($data['enabled']);

        self::assertIsArray($data['recycle_policy']);
        self::assertSame(10000, $data['recycle_policy']['max_requests']);
        self::assertSame(256, $data['recycle_policy']['memory_threshold_mb']);
        self::assertSame(7200, $data['recycle_policy']['time_limit_seconds']);

        self::assertIsArray($data['stuck_job_policy']);
        self::assertSame(300, $data['stuck_job_policy']['timeout_seconds']);
        self::assertSame(60, $data['stuck_job_policy']['check_interval_seconds']);
        self::assertFalse($data['stuck_job_policy']['move_to_dead_letter']);
    }

    #[Test]
    public function jsonOutputDisabled(): void
    {
        $config = new SupervisorConfig(enabled: false);

        $command = new SupervisorStatusCommand($config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('supervisor:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{enabled: bool, recycle_policy: null, stuck_job_policy: null} $data */
        $data = json_decode(trim($output->buffer), true);
        self::assertIsArray($data);
        self::assertFalse($data['enabled']);
        self::assertNull($data['recycle_policy']);
        self::assertNull($data['stuck_job_policy']);
    }
}
