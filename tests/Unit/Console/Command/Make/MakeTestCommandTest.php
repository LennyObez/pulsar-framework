<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MakeTestCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(MakeTestCommand::class)]
final class MakeTestCommandTest extends TestCase
{
    private string $tempDir;
    private string|false $originalDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_maketest_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);

        $this->originalDir = getcwd();
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalDir !== false) {
            chdir($this->originalDir);
        }
        $this->cleanupDir($this->tempDir);
    }

    #[Test]
    public function it_has_correct_name(): void
    {
        $command = new MakeTestCommand();

        self::assertSame('make:test', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_class_is_missing(): void
    {
        $command = new MakeTestCommand();

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function it_returns_error_for_nonexistent_class(): void
    {
        $command = new MakeTestCommand();

        $input = new ArrayInput(arguments: ['NonExistent\\ClassName']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('does not exist', $output->errorBuffer);
    }

    #[Test]
    public function it_generates_test_for_existing_class(): void
    {
        $command = new MakeTestCommand();

        // Use a class that definitely exists in the codebase
        $input = new ArrayInput(
            arguments: ['Pulsar\\Console\\ExitCode'],
            options: ['path' => 'tests/Unit'],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('Generated test', $output->buffer);

        // Verify the file was created
        $testFile = $this->tempDir . '/tests/Unit/Console/ExitCodeTest.php';
        self::assertFileExists($testFile);

        $content = file_get_contents($testFile);
        self::assertNotFalse($content);
        self::assertStringContainsString('ExitCodeTest', $content);
        self::assertStringContainsString('CoversClass', $content);
        self::assertStringContainsString('#[Test]', $content);
    }

    #[Test]
    public function it_generates_stubs_for_public_methods(): void
    {
        $command = new MakeTestCommand();

        // BufferedOutput has public methods like write, writeln, etc.
        $input = new ArrayInput(
            arguments: ['Pulsar\\Console\\Output\\BufferedOutput'],
            options: ['path' => 'tests/Unit'],
        );
        $output = new BufferedOutput();

        $command->execute($input, $output);

        $testFile = $this->tempDir . '/tests/Unit/Console/Output/BufferedOutputTest.php';
        self::assertFileExists($testFile);

        $content = file_get_contents($testFile);
        self::assertNotFalse($content);
        // Should have test stubs for the class's public methods
        self::assertStringContainsString('#[Test]', $content);
        self::assertStringContainsString('BufferedOutputTest', $content);
    }

    #[Test]
    public function it_refuses_to_overwrite_without_force(): void
    {
        // Create the test file first
        $testDir = $this->tempDir . '/tests/Unit/Console';
        mkdir($testDir, 0o755, true);
        file_put_contents($testDir . '/ExitCodeTest.php', '<?php // existing');

        $command = new MakeTestCommand();

        $input = new ArrayInput(
            arguments: ['Pulsar\\Console\\ExitCode'],
            options: ['path' => 'tests/Unit'],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('already exists', $output->errorBuffer);
    }

    #[Test]
    public function it_overwrites_with_force_option(): void
    {
        // Create the test file first
        $testDir = $this->tempDir . '/tests/Unit/Console';
        mkdir($testDir, 0o755, true);
        file_put_contents($testDir . '/ExitCodeTest.php', '<?php // existing');

        $command = new MakeTestCommand();

        $input = new ArrayInput(
            arguments: ['Pulsar\\Console\\ExitCode'],
            options: ['path' => 'tests/Unit', 'force' => true],
        );
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
    }

    private function cleanupDir(string $dir): void
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
                $this->cleanupDir($path);
            } else {
                @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }

        @rmdir($dir);
    }
}
