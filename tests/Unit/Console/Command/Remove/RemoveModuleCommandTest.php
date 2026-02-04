<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Remove;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Remove\RemoveModuleCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(RemoveModuleCommand::class)]
final class RemoveModuleCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_rm_mod_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new RemoveModuleCommand();

        self::assertSame('remove:module', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new RemoveModuleCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_returns_error_when_module_does_not_exist(): void
    {
        $command = new RemoveModuleCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules', 0o755, true);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('NonExistent');
        $input->method('getOption')->willReturnMap([
            ['path', 'app/Modules', 'app/Modules'],
        ]);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function it_removes_module_with_force_flag(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Contracts', 0o755, true);
        file_put_contents($modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'BillingServiceInterface.php', '<?php');

        $command = new RemoveModuleCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('Billing');
        $input->method('getOption')->willReturnMap([
            ['path', 'app/Modules', 'app/Modules'],
        ]);
        $input->method('hasOption')->willReturnCallback(fn(string $name): bool => $name === 'force');

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryDoesNotExist($modulePath);
    }

    #[Test]
    public function it_lists_files_on_dry_run(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        mkdir($modulePath . DIRECTORY_SEPARATOR . 'Contracts', 0o755, true);
        file_put_contents($modulePath . DIRECTORY_SEPARATOR . 'Contracts' . DIRECTORY_SEPARATOR . 'BillingServiceInterface.php', '<?php');

        $command = new RemoveModuleCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('Billing');
        $input->method('getOption')->willReturnMap([
            ['path', 'app/Modules', 'app/Modules'],
        ]);
        $input->method('hasOption')->willReturnCallback(fn(string $name): bool => $name === 'dry-run');

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('writeln');

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryExists($modulePath);
    }

    #[Test]
    public function it_aborts_when_user_declines(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $modulePath = $this->tempDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Billing';
        mkdir($modulePath, 0o755, true);

        $stdin = fopen('php://memory', 'r+');
        self::assertNotFalse($stdin);
        fwrite($stdin, "n\n");
        rewind($stdin);

        $command = new RemoveModuleCommand($stdin);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('Billing');
        $input->method('getOption')->willReturnMap([
            ['path', 'app/Modules', 'app/Modules'],
        ]);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        fclose($stdin);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryExists($modulePath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
