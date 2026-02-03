<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console\Guardian;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheck;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Studio\Command\Console\Guardian\GuardianDeployCheckCommand;

#[CoversClass(GuardianDeployCheckCommand::class)]
final class GuardianDeployCheckCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function it_is_configured_correctly(): void
    {
        $deployCheck = new DeployCheck();
        $command = new GuardianDeployCheckCommand($deployCheck);

        self::assertSame('studio:console:guardian:deploy:check', $command->name);
        self::assertSame('Run deploy readiness checks', $command->description);
        self::assertArrayHasKey('env', $command->options);
        self::assertSame('e', $command->options['env']['shortcut']);
        self::assertArrayHasKey('strict', $command->options);
        self::assertSame('s', $command->options['strict']['shortcut']);
        self::assertArrayHasKey('json', $command->options);
        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function it_displays_all_passed_as_text(): void
    {
        $check1 = $this->createStub(DeployCheckInterface::class);
        $check1->method('getName')->willReturn('SSL Certificate');
        $check1->method('check')->willReturn(CheckResult::pass('SSL Certificate', 'Valid for 90 days'));

        $check2 = $this->createStub(DeployCheckInterface::class);
        $check2->method('getName')->willReturn('Database');
        $check2->method('check')->willReturn(CheckResult::pass('Database', 'Connection established'));

        $deployCheck = new DeployCheck([$check1, $check2]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Deploy Readiness', $this->output->buffer);
        self::assertStringContainsString('production', $this->output->buffer);
        self::assertStringContainsString('[+] SSL Certificate', $this->output->buffer);
        self::assertStringContainsString('[+] Database', $this->output->buffer);
        self::assertStringContainsString('Passed: 2  Warnings: 0  Errors: 0', $this->output->buffer);
    }

    #[Test]
    public function it_displays_mixed_results_as_text(): void
    {
        $check1 = $this->createStub(DeployCheckInterface::class);
        $check1->method('getName')->willReturn('SSL');
        $check1->method('check')->willReturn(CheckResult::pass('SSL', 'OK'));

        $check2 = $this->createStub(DeployCheckInterface::class);
        $check2->method('getName')->willReturn('Caching');
        $check2->method('check')->willReturn(CheckResult::warning('Caching', 'Redis not configured', ['Configure Redis for production']));

        $check3 = $this->createStub(DeployCheckInterface::class);
        $check3->method('getName')->willReturn('Storage');
        $check3->method('check')->willReturn(CheckResult::error('Storage', 'Disk space low', ['Free up disk space', 'Add more storage']));

        $deployCheck = new DeployCheck([$check1, $check2, $check3]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check', [], ['env' => 'staging']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('staging', $this->output->buffer);
        self::assertStringContainsString('[+] SSL', $this->output->buffer);
        self::assertStringContainsString('[?] Caching', $this->output->buffer);
        self::assertStringContainsString('[!] Storage', $this->output->buffer);
        self::assertStringContainsString('-> Configure Redis for production', $this->output->buffer);
        self::assertStringContainsString('-> Free up disk space', $this->output->buffer);
        self::assertStringContainsString('-> Add more storage', $this->output->buffer);
        self::assertStringContainsString('Passed: 1  Warnings: 1  Errors: 1', $this->output->buffer);
    }

    #[Test]
    public function it_passes_with_warnings_when_not_strict(): void
    {
        $check = $this->createStub(DeployCheckInterface::class);
        $check->method('getName')->willReturn('Caching');
        $check->method('check')->willReturn(CheckResult::warning('Caching', 'Not optimized'));

        $deployCheck = new DeployCheck([$check]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function it_fails_with_warnings_when_strict(): void
    {
        $check = $this->createStub(DeployCheckInterface::class);
        $check->method('getName')->willReturn('Caching');
        $check->method('check')->willReturn(CheckResult::warning('Caching', 'Not optimized'));

        $deployCheck = new DeployCheck([$check]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check', [], ['strict' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
    }

    #[Test]
    public function it_outputs_json_when_all_pass(): void
    {
        $check = $this->createStub(DeployCheckInterface::class);
        $check->method('getName')->willReturn('SSL');
        $check->method('check')->willReturn(CheckResult::pass('SSL', 'Valid'));

        $deployCheck = new DeployCheck([$check]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{environment: string, passed: int, warnings: int, errors: int, total: int, results: list<array{name: string, severity: string, message: string, recommendations: list<string>}>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('studio:console:guardian:deploy:check', $json['command']);
        self::assertTrue($json['success']);
        self::assertSame('production', $json['data']['environment']);
        self::assertSame(1, $json['data']['passed']);
        self::assertSame(0, $json['data']['warnings']);
        self::assertSame(0, $json['data']['errors']);
        self::assertSame(1, $json['data']['total']);
        self::assertSame('pass', $json['data']['results'][0]['severity']);
    }

    #[Test]
    public function it_outputs_json_with_errors(): void
    {
        $check = $this->createStub(DeployCheckInterface::class);
        $check->method('getName')->willReturn('Storage');
        $check->method('check')->willReturn(CheckResult::error('Storage', 'Disk full', ['Add more storage']));

        $deployCheck = new DeployCheck([$check]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool, data: array{errors: int, results: list<array{name: string, severity: string, message: string, recommendations: list<string>}>}} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertFalse($json['success']);
        self::assertSame(1, $json['data']['errors']);
        self::assertSame('error', $json['data']['results'][0]['severity']);
        self::assertSame(['Add more storage'], $json['data']['results'][0]['recommendations']);
    }

    #[Test]
    public function it_fails_json_with_warnings_when_strict(): void
    {
        $check = $this->createStub(DeployCheckInterface::class);
        $check->method('getName')->willReturn('Caching');
        $check->method('check')->willReturn(CheckResult::warning('Caching', 'Not optimized'));

        $deployCheck = new DeployCheck([$check]);

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check', [], ['json' => true, 'strict' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        /** @var array{command: string, success: bool} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertFalse($json['success']);
    }

    #[Test]
    public function it_uses_production_environment_by_default(): void
    {
        $deployCheck = new DeployCheck();

        $command = new GuardianDeployCheckCommand($deployCheck);
        $input = new ArrayInput('studio:console:guardian:deploy:check');

        $exit = $command->execute($input, $this->output);

        // No checks registered, so all pass with 0 counts
        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('production', $this->output->buffer);
    }
}
