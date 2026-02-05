<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use function mkdir;

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

use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(MigrateRunCommand::class)]
final class MigrateRunCommandTest extends TestCase
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
    public function runsAppliedMigrations(): void
    {
        $this->writeMigrationFile('20240101000000', 'create_users');
        $this->writeMigrationFile('20240102000000', 'create_posts');

        $connection = $this->createMock(ConnectionInterface::class);
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

        $exit = $command->execute(new ArrayInput('migrate:run'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('20240101000000', $output->buffer);
        self::assertStringContainsString('20240102000000', $output->buffer);
        self::assertStringContainsString('Ran 2 migration(s)', $output->buffer);
    }

    #[Test]
    public function nothingToMigrate(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('execute')->willReturn(0);
        $connection->method('query')->willReturn(Result::fromArrays([]));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRunCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:run'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Nothing to migrate', $output->buffer);
    }

    #[Test]
    public function exceptionReturnsError(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willThrowException(new RuntimeException('DB error'));

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRunCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('migrate:run'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('DB error', $output->errorBuffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'migrations');
        $command = new MigrateRunCommand($runner);

        self::assertSame('migrate:run', $command->name);
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
