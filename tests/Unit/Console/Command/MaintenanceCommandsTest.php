<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\MaintenanceDisableCommand;
use Pulsar\Console\Command\MaintenanceEnableCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Deploy\MaintenanceMode;

#[CoversClass(MaintenanceEnableCommand::class)]
#[CoversClass(MaintenanceDisableCommand::class)]
final class MaintenanceCommandsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_maint_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'maintenance.json';

        if (is_file($file)) {
            @unlink($file); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function enable_command_has_correct_name(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $command = new MaintenanceEnableCommand($mode);

        self::assertSame('maintenance:enable', $command->name);
    }

    #[Test]
    public function disable_command_has_correct_name(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $command = new MaintenanceDisableCommand($mode);

        self::assertSame('maintenance:disable', $command->name);
    }

    #[Test]
    public function enable_activates_maintenance_mode(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $command = new MaintenanceEnableCommand($mode);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertTrue($mode->isActive());
        self::assertStringContainsString('enabled', $output->buffer);
    }

    #[Test]
    public function enable_with_secret_displays_secret(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $command = new MaintenanceEnableCommand($mode);

        $input = new ArrayInput(arguments: [], options: ['secret' => 'my-bypass']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertStringContainsString('my-bypass', $output->buffer);
        self::assertTrue($mode->checkSecret('my-bypass'));
    }

    #[Test]
    public function enable_warns_when_already_active(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $mode->enable();

        $command = new MaintenanceEnableCommand($mode);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('already active', $output->buffer);
    }

    #[Test]
    public function enable_with_allowed_ips(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $command = new MaintenanceEnableCommand($mode);

        $input = new ArrayInput(arguments: [], options: ['allow' => '10.0.0.1, 192.168.1.1']);
        $output = new BufferedOutput();

        $command->execute($input, $output);

        self::assertTrue($mode->isIpAllowed('10.0.0.1'));
        self::assertTrue($mode->isIpAllowed('192.168.1.1'));
    }

    #[Test]
    public function disable_deactivates_maintenance_mode(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $mode->enable();

        $command = new MaintenanceDisableCommand($mode);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertFalse($mode->isActive());
        self::assertStringContainsString('disabled', $output->buffer);
    }

    #[Test]
    public function disable_informs_when_not_active(): void
    {
        $mode = new MaintenanceMode($this->tempDir);
        $command = new MaintenanceDisableCommand($mode);

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('not active', $output->buffer);
    }
}
