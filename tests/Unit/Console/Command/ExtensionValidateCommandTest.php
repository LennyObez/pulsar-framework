<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ExtensionValidateCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(ExtensionValidateCommand::class)]
final class ExtensionValidateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_ext_validate_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->tempDir);
    }

    #[Test]
    public function it_has_correct_name(): void
    {
        $command = new ExtensionValidateCommand();

        self::assertSame('extension:validate', $command->name);
    }

    #[Test]
    public function it_returns_invalid_when_path_is_missing(): void
    {
        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
    }

    #[Test]
    public function it_returns_error_for_nonexistent_directory(): void
    {
        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: ['/nonexistent/path']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function it_returns_error_when_manifest_is_missing(): void
    {
        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: [$this->tempDir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('pulsar.json not found', $output->errorBuffer);
    }

    #[Test]
    public function it_returns_error_for_invalid_json(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pulsar.json', 'not json');

        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: [$this->tempDir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Invalid JSON', $output->errorBuffer);
    }

    #[Test]
    public function it_reports_missing_required_fields(): void
    {
        $manifest = json_encode(['description' => 'test'], JSON_THROW_ON_ERROR);
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pulsar.json', $manifest);

        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: [$this->tempDir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('name', $output->errorBuffer);
        self::assertStringContainsString('version', $output->errorBuffer);
        self::assertStringContainsString('extension_class', $output->errorBuffer);
    }

    #[Test]
    public function it_validates_valid_extension(): void
    {
        $manifest = json_encode([
            'name' => 'acme/test',
            'version' => '1.0.0',
            'extension_class' => 'Acme\\Test\\TestExtension',
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pulsar.json', $manifest);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'src', 0o755, true);

        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: [$this->tempDir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('PASS', $output->buffer);
    }

    #[Test]
    public function it_warns_about_missing_src_directory(): void
    {
        $manifest = json_encode([
            'name' => 'acme/test',
            'version' => '1.0.0',
            'extension_class' => 'Acme\\Test\\TestExtension',
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pulsar.json', $manifest);
        // No src/ directory

        $command = new ExtensionValidateCommand();

        $input = new ArrayInput(arguments: [$this->tempDir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('WARN', $output->buffer);
        self::assertStringContainsString('warning', $output->buffer);
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

        rmdir($dir);
    }
}
