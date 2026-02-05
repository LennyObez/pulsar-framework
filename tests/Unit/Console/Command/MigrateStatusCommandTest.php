<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\MigrateStatusCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\Result;
use RuntimeException;

use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(MigrateStatusCommand::class)]
final class MigrateStatusCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function showsAppliedAndPendingMigrations(): void
    {
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . '20240101000000_create_users.php', '<?php');
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . '20240102000000_create_posts.php', '<?php');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturn(Result::fromArrays([
            ['version' => '20240101000000', 'name' => 'create_users', 'batch' => 1, 'applied_at' => '2024-01-01 12:00:00'],
        ]));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateStatusCommand($runner, $repository);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Applied', $output->buffer);
        self::assertStringContainsString('Pending', $output->buffer);
        self::assertStringContainsString('20240101000000', $output->buffer);
        self::assertStringContainsString('20240102000000', $output->buffer);
    }

    #[Test]
    public function noMigrationFiles(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturn(Result::fromArrays([]));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateStatusCommand($runner, $repository);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No migration files', $output->buffer);
    }

    #[Test]
    public function exceptionReturnsError(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willThrowException(new RuntimeException('No DB'));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateStatusCommand($runner, $repository);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:status'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('No DB', $output->errorBuffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateStatusCommand($runner, $repository);

        self::assertSame('migrate:status', $command->name);
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
