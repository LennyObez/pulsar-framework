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
use Pulsar\Studio\Command\Console\Guardian\GuardianCheckCommand;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckInterface;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckResult;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Pulsar\Supervisor\Supervisor;

#[CoversClass(GuardianCheckCommand::class)]
final class GuardianCheckCommandTest extends TestCase
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
        $command = new GuardianCheckCommand($supervisor);

        self::assertSame('studio:console:guardian:check', $command->name);
        self::assertSame('Run all guardian checks', $command->description);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_displays_all_checks_passing_as_text(): void
    {
        $preflight = $this->createStub(PreflightCheckInterface::class);
        $preflight->method('check')->willReturn(new PreflightCheckResult(passed: true, message: 'Disk space OK'));

        $invariant = $this->createStub(InvariantCheckInterface::class);
        $invariant->method('check')->willReturn(new InvariantCheckResult(passed: true, message: 'Config valid'));

        $supervisor = new Supervisor(new SupervisorConfig(), preflightChecks: [$preflight], invariantChecks: [$invariant]);

        $command = new GuardianCheckCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:check');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Guardian Checks', $this->output->buffer);
        self::assertStringContainsString('Preflight:  1 passed, 0 failed', $this->output->buffer);
        self::assertStringContainsString('Invariant:  1 passed, 0 failed', $this->output->buffer);
        self::assertStringContainsString('[+] Disk space OK', $this->output->buffer);
        self::assertStringContainsString('[+] Config valid', $this->output->buffer);
        self::assertStringContainsString('All checks passed.', $this->output->buffer);
    }

    #[Test]
    public function it_displays_failing_checks_as_text(): void
    {
        $preflightPass = $this->createStub(PreflightCheckInterface::class);
        $preflightPass->method('check')->willReturn(new PreflightCheckResult(passed: true, message: 'Disk space OK'));

        $preflightFail = $this->createStub(PreflightCheckInterface::class);
        $preflightFail->method('check')->willReturn(new PreflightCheckResult(passed: false, message: 'Memory low'));

        $invariantFail = $this->createStub(InvariantCheckInterface::class);
        $invariantFail->method('check')->willReturn(new InvariantCheckResult(passed: false, message: 'Schema drift detected'));

        $supervisor = new Supervisor(
            new SupervisorConfig(),
            preflightChecks: [$preflightPass, $preflightFail],
            invariantChecks: [$invariantFail],
        );

        $command = new GuardianCheckCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:check');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Preflight:  1 passed, 1 failed', $this->output->buffer);
        self::assertStringContainsString('Invariant:  0 passed, 1 failed', $this->output->buffer);
        self::assertStringContainsString('[!] Memory low', $this->output->buffer);
        self::assertStringContainsString('[!] Schema drift detected', $this->output->buffer);
        self::assertStringContainsString('Some checks failed.', $this->output->buffer);
    }

    #[Test]
    public function it_outputs_json_when_all_checks_pass(): void
    {
        $preflight = $this->createStub(PreflightCheckInterface::class);
        $preflight->method('check')->willReturn(new PreflightCheckResult(passed: true, message: 'OK'));

        $supervisor = new Supervisor(new SupervisorConfig(), preflightChecks: [$preflight]);

        $command = new GuardianCheckCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:check', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{passed: bool, preflight: array{passed: int, failed: int}, invariant: array{passed: int, failed: int}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:check', $json['command']);
        self::assertTrue($json['success']);
        self::assertTrue($json['data']['passed']);
        self::assertSame(1, $json['data']['preflight']['passed']);
        self::assertSame(0, $json['data']['preflight']['failed']);
    }

    #[Test]
    public function it_outputs_json_when_checks_fail(): void
    {
        $preflight = $this->createStub(PreflightCheckInterface::class);
        $preflight->method('check')->willReturn(new PreflightCheckResult(passed: false, message: 'Failed check', findings: ['detail']));

        $supervisor = new Supervisor(new SupervisorConfig(), preflightChecks: [$preflight]);

        $command = new GuardianCheckCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:check', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{passed: bool, preflight: array{passed: int, failed: int, results: list<array{passed: bool, message: string, findings: list<string>}>}}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertFalse($json['success']);
        self::assertFalse($json['data']['passed']);
        self::assertSame(0, $json['data']['preflight']['passed']);
        self::assertSame(1, $json['data']['preflight']['failed']);
        self::assertSame('Failed check', $json['data']['preflight']['results'][0]['message']);
        self::assertSame(['detail'], $json['data']['preflight']['results'][0]['findings']);
    }

    #[Test]
    public function it_handles_no_checks_registered(): void
    {
        $supervisor = new Supervisor(new SupervisorConfig());

        $command = new GuardianCheckCommand($supervisor);
        $input = new ArrayInput('studio:console:guardian:check');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Preflight:  0 passed, 0 failed', $this->output->buffer);
        self::assertStringContainsString('Invariant:  0 passed, 0 failed', $this->output->buffer);
        self::assertStringContainsString('All checks passed.', $this->output->buffer);
    }
}
