<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\MigrateCreateCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

#[CoversClass(MigrateCreateCommand::class)]
final class MigrateCreateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_migrate_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function createsMigrationFile(): void
    {
        $command = new MigrateCreateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:create', ['create_users_table']), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Created migration', $output->buffer);

        $files = glob($this->tempDir . '/*.php') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('create_users_table', $files[0]);

        $content = file_get_contents($files[0]);
        self::assertIsString($content);
        self::assertStringContainsString('MigrationInterface', $content);
    }

    #[Test]
    public function missingNameReturnsInvalid(): void
    {
        $command = new MigrateCreateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:create'), $output);

        self::assertSame(ExitCode::Invalid->value, $exit);
        self::assertStringContainsString('name is required', $output->errorBuffer);
    }

    #[Test]
    public function emptyNameReturnsInvalid(): void
    {
        $command = new MigrateCreateCommand($this->tempDir);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:create', ['   ']), $output);

        self::assertSame(ExitCode::Invalid->value, $exit);
    }

    #[Test]
    public function convertsNameToSnakeCase(): void
    {
        $command = new MigrateCreateCommand($this->tempDir);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('migrate:create', ['CreateUsersTable']), $output);

        $files = glob($this->tempDir . '/*.php') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('create_users_table', $files[0]);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new MigrateCreateCommand('/tmp');

        self::assertSame('migrate:create', $command->name);
        self::assertNotEmpty($command->arguments);
    }
}
