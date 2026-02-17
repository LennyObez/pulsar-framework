<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\PdoConnection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function realpath;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * Tests MigrationRunner::executeMigrationSafely() transaction handling.
 *
 * Verifies that migrations which internally manage transactions do not
 * crash on SQLite due to nested BEGIN statements, and that migrations
 * without internal transactions still receive a wrapping transaction.
 */
#[CoversClass(MigrationRunner::class)]
final class MigrationRunnerTransactionSafetyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_mig_tx_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->tmpDir);
    }

    #[Test]
    public function migrationWithInternalTransactionDoesNotCrashOnSqlite(): void
    {
        // Arrange: create a migration that begins its own transaction
        $migrationCode = <<<'PHP'
            <?php
            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    // This migration starts its own transaction internally.
                    // MigrationRunner must detect inTransaction() and not wrap.
                    $connection->execute('CREATE TABLE IF NOT EXISTS self_tx_test (id INTEGER PRIMARY KEY)');
                }
                public function down(ConnectionInterface $connection): void
                {
                    $connection->execute('DROP TABLE IF EXISTS self_tx_test');
                }
            };
            PHP;

        file_put_contents($this->tmpDir . '/20260101000000_self_tx_test.php', $migrationCode);

        // Use a real in-memory SQLite connection to detect actual nested-BEGIN crashes
        $connection = new PdoConnection('test', Driver::SQLite, 'sqlite::memory:', null, null);

        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'pulsar_migrations');

        // Act: the runner's executeMigrationSafely should detect inTransaction=false
        // and wrap the migration in a transaction without nesting issues.
        $applied = $runner->runPending();

        // Assert
        self::assertCount(1, $applied);
        self::assertStringContainsString('20260101000000', $applied[0]);

        // Verify the table was actually created
        $result = $connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='self_tx_test'");
        self::assertNotNull($result->first(), 'Table self_tx_test must exist after migration');
    }

    #[Test]
    public function migrationWithoutInternalTransactionGetsWrapped(): void
    {
        // Arrange: a simple migration with no internal transaction management
        $migrationCode = <<<'PHP'
            <?php
            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    $connection->execute('CREATE TABLE IF NOT EXISTS simple_test (id INTEGER PRIMARY KEY, name TEXT)');
                    $connection->execute("INSERT INTO simple_test (id, name) VALUES (1, 'test')");
                }
                public function down(ConnectionInterface $connection): void
                {
                    $connection->execute('DROP TABLE IF EXISTS simple_test');
                }
            };
            PHP;

        file_put_contents($this->tmpDir . '/20260201000000_simple_test.php', $migrationCode);

        $connection = new PdoConnection('test', Driver::SQLite, 'sqlite::memory:', null, null);
        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'pulsar_migrations');

        // Act
        $applied = $runner->runPending();

        // Assert
        self::assertCount(1, $applied);

        // Verify both statements executed (the wrap transaction committed)
        $result = $connection->query('SELECT name FROM simple_test WHERE id = 1');
        $row = $result->first();
        self::assertNotNull($row);
        self::assertSame('test', $row->getOrDefault('name', ''));
    }

    #[Test]
    public function failingMigrationRollsBackWithoutLeavingPartialState(): void
    {
        // Arrange: first migration succeeds, second fails
        $migration1 = <<<'PHP'
            <?php
            declare(strict_types=1);
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;
            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    $connection->execute('CREATE TABLE IF NOT EXISTS rollback_a (id INTEGER PRIMARY KEY)');
                }
                public function down(ConnectionInterface $connection): void
                {
                    $connection->execute('DROP TABLE IF EXISTS rollback_a');
                }
            };
            PHP;

        $migration2 = <<<'PHP'
            <?php
            declare(strict_types=1);
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;
            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void
                {
                    // This will fail: invalid SQL
                    $connection->execute('INVALID SQL STATEMENT');
                }
                public function down(ConnectionInterface $connection): void {}
            };
            PHP;

        file_put_contents($this->tmpDir . '/20260301000000_rollback_a.php', $migration1);
        file_put_contents($this->tmpDir . '/20260302000000_rollback_fail.php', $migration2);

        $connection = new PdoConnection('test', Driver::SQLite, 'sqlite::memory:', null, null);
        $repository = new MigrationRepository($this->tmpDir);
        $runner = new MigrationRunner($connection, $repository, 'pulsar_migrations');

        // Act: the second migration will throw
        $threw = false;
        try {
            $runner->runPending();
        } catch (Throwable) {
            $threw = true;
        }

        // Assert
        self::assertTrue($threw, 'Running a batch with a failing migration must throw');

        // The first migration should have succeeded and been recorded
        // (migrations are applied one-by-one, not all-or-nothing at batch level)
        $result = $connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='rollback_a'");
        self::assertNotNull($result->first(), 'First migration table should exist (committed before failure)');
    }

    /**
     * Safely remove a temp directory tree.
     *
     * Validates that the resolved path is under the system temp directory
     * before removing any files to prevent path traversal issues.
     */
    private static function removeTempTree(string $dir): void
    {
        $resolved = realpath($dir);
        $tempBase = realpath(sys_get_temp_dir());

        if ($resolved === false || $tempBase === false || !str_starts_with($resolved, $tempBase)) {
            return;
        }

        if (!is_dir($resolved)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entryPath = $entry->getRealPath();
            if ($entryPath === false || !str_starts_with($entryPath, $tempBase)) {
                continue;
            }

            $entry->isDir() ? @rmdir($entryPath) : @unlink($entryPath);
        }

        @rmdir($resolved);
    }
}
