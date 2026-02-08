<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeExtensionCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

#[CoversClass(MakeExtensionCommand::class)]
final class MakeExtensionCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_make_ext_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_has_the_correct_name(): void
    {
        $command = new MakeExtensionCommand();

        self::assertSame('make:extension', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_name_is_missing(): void
    {
        $command = new MakeExtensionCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn(null);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        self::assertSame(ExitCode::Invalid->value, $command->execute($input, $output));
    }

    #[Test]
    public function it_scaffolds_extension_structure(): void
    {
        $command = new MakeExtensionCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'extensions', 0o755, true);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('my-widget');
        $input->method('getOption')->willReturnMap([
            ['vendor', 'acme', 'acme'],
            ['path', 'extensions', 'extensions'],
        ]);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createStub(OutputInterface::class);

        $result = $command->execute($input, $output);

        if ($originalDir !== false) {
            chdir($originalDir);
        }

        self::assertSame(ExitCode::Success->value, $result);

        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . 'my-widget';
        self::assertDirectoryExists($extPath . DIRECTORY_SEPARATOR . 'src');
        self::assertDirectoryExists($extPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controller');
        self::assertFileExists($extPath . DIRECTORY_SEPARATOR . 'pulsar.json');
        self::assertFileExists($extPath . DIRECTORY_SEPARATOR . 'composer.json');
        self::assertFileExists($extPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'MyWidgetExtension.php');
        self::assertFileExists($extPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'MyWidgetServiceProvider.php');
        self::assertFileExists($extPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'MyWidgetService.php');
        self::assertFileExists($extPath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'MyWidgetController.php');
    }

    #[Test]
    public function it_returns_error_when_extension_already_exists(): void
    {
        $command = new MakeExtensionCommand();

        $originalDir = getcwd();
        chdir($this->tempDir);

        $extPath = $this->tempDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . 'my-widget';
        mkdir($extPath, 0o755, true);

        $input = $this->createStub(InputInterface::class);
        $input->method('getArgument')->willReturn('my-widget');
        $input->method('getOption')->willReturnMap([
            ['vendor', 'acme', 'acme'],
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
