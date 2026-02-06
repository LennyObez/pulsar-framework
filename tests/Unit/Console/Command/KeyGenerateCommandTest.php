<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use function bin2hex;
use function ctype_xdigit;

use const DIRECTORY_SEPARATOR;

use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\KeyGenerateCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function random_bytes;
use function rewind;
use function rmdir;
use function scandir;
use function strlen;
use function unlink;

#[CoversClass(KeyGenerateCommand::class)]
final class KeyGenerateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_keygen_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsKeyGenerate(): void
    {
        $command = new KeyGenerateCommand($this->tempDir);

        self::assertSame('key:generate', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = new KeyGenerateCommand($this->tempDir);

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function printsValidHexKeyByDefault(): void
    {
        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('key:generate'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertMatchesRegularExpression('/^PULSAR_MASTER_KEY=[0-9a-f]{64}\r?\n$/', $output->buffer);
    }

    #[Test]
    public function outputKeyIsValidHexLength(): void
    {
        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('key:generate'), $output);

        $hex = substr(trim($output->buffer), strlen('PULSAR_MASTER_KEY='));
        self::assertSame(64, strlen($hex));
        self::assertTrue(ctype_xdigit($hex));
    }

    // ---------------------------------------------------------------
    // --write: .env does not exist
    // ---------------------------------------------------------------

    #[Test]
    public function writeCreatesEnvFromTemplateWhenAvailable(): void
    {
        // Place a .env.example in the temp dir
        $exampleContent = "APP_ENV=local\nPULSAR_MASTER_KEY=\nDB_HOST=127.0.0.1\n";
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . '.env.example', $exampleContent);

        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        self::assertFileExists($envFile);

        $content = file_get_contents($envFile);
        self::assertIsString($content);

        // Template structure preserved
        self::assertStringContainsString('APP_ENV=local', $content);
        self::assertStringContainsString('DB_HOST=127.0.0.1', $content);

        // Key was filled in (not empty)
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[0-9a-f]{64}/', $content);
        self::assertStringContainsString('Created', $output->buffer);
    }

    #[Test]
    public function writeCreatesMinimalEnvWithoutTemplate(): void
    {
        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        self::assertFileExists($envFile);

        $content = file_get_contents($envFile);
        self::assertIsString($content);
        self::assertMatchesRegularExpression('/^PULSAR_MASTER_KEY=[0-9a-f]{64}$/', trim($content));
    }

    // ---------------------------------------------------------------
    // --write: .env exists, key is empty
    // ---------------------------------------------------------------

    #[Test]
    public function writeFillsEmptyKeyInExistingEnv(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "APP_ENV=local\nPULSAR_MASTER_KEY=\nDB_HOST=127.0.0.1\n");

        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $content = file_get_contents($envFile);
        self::assertIsString($content);
        self::assertStringContainsString('APP_ENV=local', $content);
        self::assertStringContainsString('DB_HOST=127.0.0.1', $content);
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[0-9a-f]{64}/', $content);
        self::assertStringContainsString('set in', $output->buffer);
    }

    // ---------------------------------------------------------------
    // --write: .env exists, key is absent
    // ---------------------------------------------------------------

    #[Test]
    public function writeAppendsKeyWhenMissingFromEnv(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "APP_ENV=local\n");

        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $content = file_get_contents($envFile);
        self::assertIsString($content);
        self::assertStringContainsString('APP_ENV=local', $content);
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[0-9a-f]{64}/', $content);
        self::assertStringContainsString('appended', $output->buffer);
    }

    // ---------------------------------------------------------------
    // --write: .env exists, key is already set (interactive)
    // ---------------------------------------------------------------

    #[Test]
    public function writePromptsAndAbortsOnNo(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "PULSAR_MASTER_KEY=oldvalue123\n");

        $stdin = $this->createStdinWith("n\n");
        $command = new KeyGenerateCommand($this->tempDir, $stdin);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Aborted', $output->buffer);

        // Key unchanged
        $content = file_get_contents($envFile);
        self::assertStringContainsString('PULSAR_MASTER_KEY=oldvalue123', $content !== false ? $content : '');
    }

    #[Test]
    public function writePromptsAndReplacesOnYes(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "PULSAR_MASTER_KEY=oldvalue123\n");

        $stdin = $this->createStdinWith("y\n");
        $command = new KeyGenerateCommand($this->tempDir, $stdin);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('replaced', $output->buffer);

        $content = file_get_contents($envFile);
        self::assertIsString($content);
        self::assertStringNotContainsString('oldvalue123', $content);
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[0-9a-f]{64}/', $content);
    }

    #[Test]
    public function writeOverwritesWithForceSkipsPrompt(): void
    {
        $envFile = $this->tempDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, "PULSAR_MASTER_KEY=oldvalue123\n");

        $command = new KeyGenerateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('key:generate', options: ['write' => true, 'force' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        $content = file_get_contents($envFile);
        self::assertIsString($content);
        self::assertStringNotContainsString('oldvalue123', $content);
        self::assertMatchesRegularExpression('/PULSAR_MASTER_KEY=[0-9a-f]{64}/', $content);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return resource
     */
    private function createStdinWith(string $input): mixed
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $input);
        rewind($stream);

        return $stream;
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
