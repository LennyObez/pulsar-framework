<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\KeyRotateCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sodium_bin2hex;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(KeyRotateCommand::class)]
final class KeyRotateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_keyrotate_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsKeyRotate(): void
    {
        $command = new KeyRotateCommand($this->tempDir);

        self::assertSame('key:rotate', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = new KeyRotateCommand($this->tempDir);

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function printsInstructionsWithoutWriteFlag(): void
    {
        $command = new KeyRotateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('key:rotate'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('PULSAR_MASTER_KEY=', $output->buffer);
        self::assertStringContainsString('PULSAR_MASTER_KEY_PREVIOUS', $output->buffer);
        self::assertStringContainsString('--write', $output->buffer);
    }

    #[Test]
    public function writeFailsWhenEnvFileMissing(): void
    {
        $command = new KeyRotateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:rotate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('.env file not found', $output->errorBuffer);
    }

    #[Test]
    public function writeFailsWhenKeyNotInEnv(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "APP_ENV=local\n");

        $command = new KeyRotateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:rotate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('not found or invalid', $output->errorBuffer);
    }

    #[Test]
    public function writeRotatesKeySuccessfully(): void
    {
        $currentKey = sodium_bin2hex(random_bytes(32));
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "APP_ENV=local\nPULSAR_MASTER_KEY={$currentKey}\n");

        $command = new KeyRotateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:rotate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('rotated successfully', $output->buffer);

        $content = file_get_contents($envFile);
        self::assertIsString($content);

        // New key should be different from current
        self::assertStringNotContainsString("PULSAR_MASTER_KEY={$currentKey}", $content);
        // Previous key should be set to old current
        self::assertStringContainsString("PULSAR_MASTER_KEY_PREVIOUS={$currentKey}", $content);
        // New key should be valid 64-char hex
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[0-9a-f]{64}/', $content);
    }

    #[Test]
    public function writeUpdatesExistingPreviousKey(): void
    {
        $currentKey = sodium_bin2hex(random_bytes(32));
        $oldPrevious = sodium_bin2hex(random_bytes(32));
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents(
            $envFile,
            "PULSAR_MASTER_KEY={$currentKey}\nPULSAR_MASTER_KEY_PREVIOUS={$oldPrevious}\n",
        );

        $command = new KeyRotateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:rotate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $content = file_get_contents($envFile);
        self::assertIsString($content);

        // Previous key should now be the old current (not the old previous)
        self::assertStringContainsString("PULSAR_MASTER_KEY_PREVIOUS={$currentKey}", $content);
        self::assertStringNotContainsString($oldPrevious, $content);
    }

    #[Test]
    public function writePreservesOtherEnvVariables(): void
    {
        $currentKey = sodium_bin2hex(random_bytes(32));
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents(
            $envFile,
            "APP_ENV=production\nPULSAR_MASTER_KEY={$currentKey}\nDB_HOST=127.0.0.1\n",
        );

        $command = new KeyRotateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:rotate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $content = file_get_contents($envFile);
        self::assertIsString($content);
        self::assertStringContainsString('APP_ENV=production', $content);
        self::assertStringContainsString('DB_HOST=127.0.0.1', $content);
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
