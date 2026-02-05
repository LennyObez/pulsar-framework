<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\MigrateRollbackCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\Result;
use RuntimeException;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(MigrateRollbackCommand::class)]
final class MigrateRollbackCommandTest extends TestCase
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
    public function nothingToRollback(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturn(Result::fromArrays([]));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRollbackCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:rollback'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Nothing to rollback', $output->buffer);
    }

    #[Test]
    public function rollbackLastBatch(): void
    {
        $this->writeMigrationFile('20240101000000', 'create_users');

        $queryCount = 0;
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturnCallback(
            static function (string $sql) use (&$queryCount): Result {
                $queryCount++;
                if (str_contains($sql, 'MAX(batch)')) {
                    return Result::fromArrays([['max_batch' => 1]]);
                }

                return Result::fromArrays([
                    ['version' => '20240101000000', 'name' => 'create_users', 'batch' => 1, 'applied_at' => '2024-01-01 12:00:00'],
                ]);
            },
        );
        $connection->method('transaction')->willReturnCallback(
            static fn(callable $callback) => $callback($connection),
        );

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRollbackCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:rollback'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Rolled back 1 migration(s)', $output->buffer);
    }

    #[Test]
    public function exceptionReturnsError(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willThrowException(new RuntimeException('DB fail'));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRollbackCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:rollback'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('DB fail', $output->errorBuffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRollbackCommand($runner);

        self::assertSame('migrate:rollback', $command->name);
    }

    private function writeMigrationFile(string $version, string $name): void
    {
        $content = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void {}
                public function down(ConnectionInterface $connection): void {}
            };
            PHP;

        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . "{$version}_{$name}.php", $content);
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
