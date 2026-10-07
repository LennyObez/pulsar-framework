<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\MigrateRunCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\Result;
use RuntimeException;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(MigrateRunCommand::class)]
final class MigrateRunCommandErrorChainTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_errchain_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    #[Test]
    public function displaysMainAndPreviousExceptionMessages(): void
    {
        // Arrange: a migration that throws a RuntimeException with a cause.
        // MigrationRunner wraps it in DatabaseException::migrationFailed()
        // which preserves the original as getPrevious().
        $this->writeMigrationFile('20260101000000', 'failing_migration', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;
            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    throw new \RuntimeException('Connection refused to 127.0.0.1:5432');
                }
                public function down(ConnectionInterface $connection): void {}
            };
            PHP);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturnCallback(
            static fn(string $sql): Result => Result::fromArrays(
                str_contains($sql, 'MAX(batch)') ? [['max_batch' => null]] : [],
            ),
        );
        $connection->method('transaction')->willReturnCallback(
            static fn(callable $callback) => $callback($connection),
        );

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRunCommand($runner);
        $output = new BufferedOutput();

        // Act
        $exitCode = $command->execute(new ArrayInput('migrate:run'), $output);

        // Assert
        self::assertSame(ExitCode::Error->value, $exitCode);

        // Main message: DatabaseException::migrationFailed wraps with "Migration <ver> (up) failed"
        self::assertStringContainsString(
            'Migration',
            $output->errorBuffer,
            'The outer exception message must appear in error output',
        );
        self::assertStringContainsString(
            'failed',
            $output->errorBuffer,
        );

        // "Caused by:" with the original RuntimeException message
        self::assertStringContainsString(
            'Caused by:',
            $output->errorBuffer,
            'The "Caused by:" label must appear when a previous exception exists',
        );
        self::assertStringContainsString(
            'Connection refused to 127.0.0.1:5432',
            $output->errorBuffer,
            'The previous exception message must appear after "Caused by:"',
        );
    }

    #[Test]
    public function exceptionWithoutPreviousShowsOnlyMainMessage(): void
    {
        // Arrange: connection's driver() throws directly (no previous),
        // which causes ensureMigrationTable to fail before wrapping.
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willThrowException(
            new RuntimeException('Table already exists'),
        );

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRunCommand($runner);
        $output = new BufferedOutput();

        // Act
        $exitCode = $command->execute(new ArrayInput('migrate:run'), $output);

        // Assert
        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Table already exists', $output->errorBuffer);
        self::assertStringNotContainsString(
            'Caused by:',
            $output->errorBuffer,
            '"Caused by:" must not appear when there is no previous exception',
        );
    }

    #[Test]
    public function deeplyNestedPreviousShowsImmediateCause(): void
    {
        // Arrange: a migration that throws a nested exception chain.
        // The inner RuntimeException has its own cause, but MigrateRunCommand
        // only shows getPrevious() of the caught exception.
        $this->writeMigrationFile('20260315120000', 'nested_error', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;
            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    $root = new \RuntimeException('disk I/O error');
                    throw new \RuntimeException('SQL execution failed', 0, $root);
                }
                public function down(ConnectionInterface $connection): void {}
            };
            PHP);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturnCallback(
            static fn(string $sql): Result => Result::fromArrays(
                str_contains($sql, 'MAX(batch)') ? [['max_batch' => null]] : [],
            ),
        );
        $connection->method('transaction')->willReturnCallback(
            static fn(callable $callback) => $callback($connection),
        );

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRunCommand($runner);
        $output = new BufferedOutput();

        // Act
        $exitCode = $command->execute(new ArrayInput('migrate:run'), $output);

        // Assert
        self::assertSame(ExitCode::Error->value, $exitCode);

        // The caught exception is DatabaseException::migrationFailed(... previous: RuntimeException).
        // That RuntimeException's message is "SQL execution failed".
        // MigrateRunCommand shows getPrevious() of the caught DatabaseException.
        self::assertStringContainsString(
            'Caused by:',
            $output->errorBuffer,
        );
        self::assertStringContainsString(
            'SQL execution failed',
            $output->errorBuffer,
            'The immediate cause (getPrevious) must be shown',
        );
    }

    private function writeMigrationFile(string $version, string $name, string $content): void
    {
        file_put_contents(
            $this->tmpDir . DIRECTORY_SEPARATOR . "{$version}_{$name}.php",
            $content,
        );
    }
}
