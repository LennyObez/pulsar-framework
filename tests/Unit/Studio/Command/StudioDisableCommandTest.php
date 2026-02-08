<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command;

use function file_get_contents;
use function file_put_contents;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\StudioDisableCommand;

use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(StudioDisableCommand::class)]
final class StudioDisableCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_studio_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new StudioDisableCommand($this->tmpDir);

        self::assertSame('studio:disable', $command->name);
        self::assertSame('Disable Studio in configuration', $command->description);
    }

    #[Test]
    public function disablesStudioWhenEnabled(): void
    {
        $configPath = $this->tmpDir . '/config/studio.php';
        file_put_contents($configPath, "<?php\nreturn [\n    'enabled' => true,\n];");

        $command = new StudioDisableCommand($this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:disable'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[SUCCESS] Studio has been disabled.', $output->buffer);
        self::assertStringContainsString('Restart your application', $output->buffer);

        $contents = file_get_contents($configPath);
        self::assertIsString($contents);
        self::assertStringContainsString("'enabled' => false", $contents);
    }

    #[Test]
    public function returnsSuccessWhenAlreadyDisabled(): void
    {
        $configPath = $this->tmpDir . '/config/studio.php';
        file_put_contents($configPath, "<?php\nreturn [\n    'enabled' => false,\n];");

        $command = new StudioDisableCommand($this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:disable'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[INFO] Studio is already disabled.', $output->buffer);
    }

    #[Test]
    public function returnsErrorWhenConfigFileNotFound(): void
    {
        $command = new StudioDisableCommand($this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:disable'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Config file not found', $output->errorBuffer);
    }

    #[Test]
    public function handlesConfigWithoutEnabledKey(): void
    {
        $configPath = $this->tmpDir . '/config/studio.php';
        file_put_contents($configPath, "<?php\nreturn [\n    'storage_path' => 'storage/studio',\n];");

        $command = new StudioDisableCommand($this->tmpDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:disable'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('already disabled', $output->buffer);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
