<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\InitCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function bin2hex;
use function file_exists;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(InitCommand::class)]
final class InitCommandTest extends TestCase
{
    private string $tempDir;
    private BufferedOutput $output;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_init_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
        $this->output = new BufferedOutput();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function nameIsInit(): void
    {
        $command = new InitCommand();

        self::assertSame('init', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $command = new InitCommand();

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function initializesProjectStructure(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'myproject';
        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('initialized successfully', $this->output->buffer);

        // Verify directory structure
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'app'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Middleware'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'config'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'public'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'extensions'));
        self::assertTrue(is_dir($targetDir . DIRECTORY_SEPARATOR . 'tests'));

        // Verify files created
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php'));
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php'));
        self::assertTrue(file_exists($targetDir . DIRECTORY_SEPARATOR . '.gitignore'));
    }

    #[Test]
    public function skipsExistingFilesWithoutForce(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'existing';
        mkdir($targetDir . DIRECTORY_SEPARATOR . 'public', 0o755, true);
        file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php', 'original');

        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Skipped', $this->output->buffer);

        // File content preserved
        $content = file_get_contents($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
        self::assertSame('original', $content);
    }

    #[Test]
    public function forceOverwritesExistingFiles(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'forced';
        mkdir($targetDir . DIRECTORY_SEPARATOR . 'public', 0o755, true);
        file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php', 'original');

        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir], options: ['force' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        // File content overwritten
        $content = file_get_contents($targetDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
        self::assertIsString($content);
        self::assertNotSame('original', $content);
        self::assertStringContainsString('Kernel', $content);
    }

    #[Test]
    public function showsNextSteps(): void
    {
        $targetDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nextproject';
        $command = new InitCommand();
        $input = new ArrayInput(arguments: [$targetDir]);
        $command->execute($input, $this->output);

        self::assertStringContainsString('Next steps', $this->output->buffer);
        self::assertStringContainsString('composer install', $this->output->buffer);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var list<string> $items */
        $items = scandir($dir);

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
