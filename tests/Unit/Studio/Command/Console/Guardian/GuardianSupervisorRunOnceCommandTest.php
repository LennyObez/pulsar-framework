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
use Pulsar\Studio\Command\Console\Guardian\GuardianSupervisorRunOnceCommand;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Pulsar\Supervisor\Supervisor;

#[CoversClass(GuardianSupervisorRunOnceCommand::class)]
final class GuardianSupervisorRunOnceCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $supervisor = new Supervisor(new SupervisorConfig());
        $command = new GuardianSupervisorRunOnceCommand($supervisor);

        self::assertSame('studio:console:guardian:supervisor:run-once', $command->name);
        self::assertSame('Run a single supervisor evaluation cycle', $command->description);
        self::assertArrayHasKey('requests', $command->options);
        self::assertSame('r', $command->options['requests']['shortcut']);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_displays_no_recycle_triggered_as_text(): void
    {
        $preflight = $this->createStub(PreflightCheckInterface::class);
        $preflight->method('check')->willReturn(new PreflightCheckResult(passed: true, message: 'OK'));

        // Supervisor disabled = no recycle policy, shouldRecycle returns null
        $supervisor = new Supervisor(new SupervisorConfig(enabled: false), preflightChecks: [$preflight]);

        $command = new GuardianSupervisorRunOnceCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:supervisor:run-once');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Supervisor Run-Once', $this->output->buffer);
        self::assertStringContainsString('Memory:', $this->output->buffer);
        self::assertStringContainsString('Uptime:', $this->output->buffer);
        self::assertStringContainsString('Requests:  0', $this->output->buffer);
        self::assertStringContainsString('Recycle:   not triggered', $this->output->buffer);
        self::assertStringContainsString('Preflight: 1 passed, 0 failed', $this->output->buffer);
    }

    #[Test]
    public function it_displays_recycle_triggered_as_text(): void
    {
        // Enabled supervisor with very low max_requests threshold so it triggers immediately
        $config = new SupervisorConfig(enabled: true, recycleMaxRequests: 1);
        $supervisor = new Supervisor($config);

        $command = new GuardianSupervisorRunOnceCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:supervisor:run-once', [], ['requests' => '500']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Requests:  500', $this->output->buffer);
        self::assertStringContainsString('Recycle:   TRIGGERED', $this->output->buffer);
        self::assertStringContainsString('max_requests', $this->output->buffer);
        self::assertStringContainsString('graceful_restart', $this->output->buffer);
    }

    #[Test]
    public function it_outputs_no_recycle_as_json(): void
    {
        $supervisor = new Supervisor(new SupervisorConfig(enabled: false));

        $command = new GuardianSupervisorRunOnceCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:supervisor:run-once', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{current_state: array{memory_mb: int, uptime_seconds: int, request_count: int}, recycle: array{triggered: bool}, preflight: array{passed: int, failed: int}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:supervisor:run-once', $json['command']);
        self::assertTrue($json['success']);
        self::assertFalse($json['data']['recycle']['triggered']);
        self::assertSame(0, $json['data']['current_state']['request_count']);
        self::assertSame(0, $json['data']['preflight']['passed']);
        self::assertSame(0, $json['data']['preflight']['failed']);
    }

    #[Test]
    public function it_outputs_recycle_triggered_as_json(): void
    {
        $preflightPass = $this->createStub(PreflightCheckInterface::class);
        $preflightPass->method('check')->willReturn(new PreflightCheckResult(passed: true, message: 'OK'));

        $preflightFail = $this->createStub(PreflightCheckInterface::class);
        $preflightFail->method('check')->willReturn(new PreflightCheckResult(passed: false, message: 'Warn'));

        // Low max_requests to trigger recycle
        $config = new SupervisorConfig(enabled: true, recycleMaxRequests: 1);
        $supervisor = new Supervisor($config, preflightChecks: [$preflightPass, $preflightFail]);

        $command = new GuardianSupervisorRunOnceCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:supervisor:run-once', [], ['json' => true, 'requests' => '10000']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{current_state: array{request_count: int}, recycle: array{triggered: bool, reason: string, action: string}, preflight: array{passed: int, failed: int}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertTrue($json['data']['recycle']['triggered']);
        self::assertSame('max_requests', $json['data']['recycle']['reason']);
        self::assertSame('graceful_restart', $json['data']['recycle']['action']);
        self::assertSame(10000, $json['data']['current_state']['request_count']);
        self::assertSame(1, $json['data']['preflight']['passed']);
        self::assertSame(1, $json['data']['preflight']['failed']);
    }

    #[Test]
    public function it_handles_failed_preflight_checks_as_text(): void
    {
        $preflightFail1 = $this->createStub(PreflightCheckInterface::class);
        $preflightFail1->method('check')->willReturn(new PreflightCheckResult(passed: false, message: 'Disk full'));

        $preflightFail2 = $this->createStub(PreflightCheckInterface::class);
        $preflightFail2->method('check')->willReturn(new PreflightCheckResult(passed: false, message: 'Memory exhausted'));

        $supervisor = new Supervisor(new SupervisorConfig(enabled: false), preflightChecks: [$preflightFail1, $preflightFail2]);

        $command = new GuardianSupervisorRunOnceCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:supervisor:run-once');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Preflight: 0 passed, 2 failed', $this->output->buffer);
    }
}
