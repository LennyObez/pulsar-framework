<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Remove;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Remove\RemoveExtensionCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(RemoveExtensionCommand::class)]
final class RemoveExtensionCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_rm_ext_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new RemoveExtensionCommand();

        self::assertSame('remove:extension', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new RemoveExtensionCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_removes_extension_with_force_flag(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . 'my-widget';
        mkdir($extPath . DIRECTORY_SEPARATOR . 'src', 0o755, true);
        file_put_contents($extPath . DIRECTORY_SEPARATOR . 'pulsar.json', '{}');

        $command = new RemoveExtensionCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('my-widget');
        $input->method('getOption')->willReturnMap([
            ['path', 'extensions', 'extensions'],
        ]);
        $input->method('hasOption')->willReturnCallback(fn(string $name): bool => $name === 'force');

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryDoesNotExist($extPath);
    }

    #[Test]
    public function it_returns_error_when_extension_does_not_exist(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'extensions', 0o755, true);

        $command = new RemoveExtensionCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('nonexistent');
        $input->method('getOption')->willReturnMap([
            ['path', 'extensions', 'extensions'],
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
    public function it_lists_files_on_dry_run(): void
    {
        $originalDir = getcwd();
        chdir($this->tempDir);

        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . 'my-widget';
        mkdir($extPath . DIRECTORY_SEPARATOR . 'src', 0o755, true);
        file_put_contents($extPath . DIRECTORY_SEPARATOR . 'pulsar.json', '{}');

        $command = new RemoveExtensionCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('my-widget');
        $input->method('getOption')->willReturnMap([
            ['path', 'extensions', 'extensions'],
        ]);
        $input->method('hasOption')->willReturnCallback(fn(string $name): bool => $name === 'dry-run');

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('writeln');

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);
        self::assertDirectoryExists($extPath);
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
