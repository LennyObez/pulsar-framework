<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Guardian;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\Guardian\GuardianSupervisorStatusCommand;

#[CoversClass(GuardianSupervisorStatusCommand::class)]
final class GuardianSupervisorStatusCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $config = new SupervisorConfig();
        $command = new GuardianSupervisorStatusCommand($config);

        self::assertSame('studio:console:guardian:supervisor:status', $command->name);
        self::assertSame('Display supervisor configuration status', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_displays_enabled_supervisor_status_as_text(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 5000,
            recycleMemoryThresholdMb: 512,
            recycleTimeLimitSeconds: 3600,
            stuckJobTimeoutSeconds: 600,
            stuckJobCheckIntervalSeconds: 30,
            stuckJobMoveToDeadLetter: true,
        );

        $command = new GuardianSupervisorStatusCommand($config);
        $input = new ArrayInput('studio:console:guardian:supervisor:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Supervisor Status', $this->output->buffer);
        self::assertStringContainsString('Enabled: yes', $this->output->buffer);
        self::assertStringContainsString('Recycle Policy:', $this->output->buffer);
        self::assertStringContainsString('Max requests:        5000', $this->output->buffer);
        self::assertStringContainsString('Memory threshold:    512 MB', $this->output->buffer);
        self::assertStringContainsString('Time limit:          3600 s', $this->output->buffer);
        self::assertStringContainsString('Stuck Job Policy:', $this->output->buffer);
        self::assertStringContainsString('Timeout:             600 s', $this->output->buffer);
        self::assertStringContainsString('Check interval:      30 s', $this->output->buffer);
        self::assertStringContainsString('Dead letter on fail: yes', $this->output->buffer);
    }

    #[Test]
    public function it_displays_disabled_supervisor_status(): void
    {
        $config = new SupervisorConfig(
            enabled: false,
            stuckJobMoveToDeadLetter: false,
        );

        $command = new GuardianSupervisorStatusCommand($config);
        $input = new ArrayInput('studio:console:guardian:supervisor:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Enabled: no', $this->output->buffer);
        self::assertStringContainsString('Dead letter on fail: no', $this->output->buffer);
    }

    #[Test]
    public function it_outputs_json(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 256,
            recycleTimeLimitSeconds: 7200,
            stuckJobTimeoutSeconds: 300,
            stuckJobCheckIntervalSeconds: 60,
            stuckJobMoveToDeadLetter: true,
        );

        $command = new GuardianSupervisorStatusCommand($config);
        $input = new ArrayInput('studio:console:guardian:supervisor:status', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{enabled: bool, recycle: array{max_requests: int, memory_threshold_mb: int, time_limit_seconds: int}, stuck_job: array{timeout_seconds: int, check_interval_seconds: int, move_to_dead_letter: bool}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:supervisor:status', $json['command']);
        self::assertTrue($json['success']);
        self::assertTrue($json['data']['enabled']);
        self::assertSame(10000, $json['data']['recycle']['max_requests']);
        self::assertSame(256, $json['data']['recycle']['memory_threshold_mb']);
        self::assertSame(7200, $json['data']['recycle']['time_limit_seconds']);
        self::assertSame(300, $json['data']['stuck_job']['timeout_seconds']);
        self::assertSame(60, $json['data']['stuck_job']['check_interval_seconds']);
        self::assertTrue($json['data']['stuck_job']['move_to_dead_letter']);
    }

    #[Test]
    public function it_uses_default_config_values(): void
    {
        $config = new SupervisorConfig();

        $command = new GuardianSupervisorStatusCommand($config);
        $input = new ArrayInput('studio:console:guardian:supervisor:status');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Enabled: no', $this->output->buffer);
        self::assertStringContainsString('Max requests:        10000', $this->output->buffer);
        self::assertStringContainsString('Memory threshold:    256 MB', $this->output->buffer);
        self::assertStringContainsString('Time limit:          7200 s', $this->output->buffer);
        self::assertStringContainsString('Timeout:             300 s', $this->output->buffer);
        self::assertStringContainsString('Check interval:      60 s', $this->output->buffer);
        self::assertStringContainsString('Dead letter on fail: yes', $this->output->buffer);
    }
}
