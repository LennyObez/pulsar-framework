<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\PdoConnection;

use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(MigrationRunner::class)]
final class MigrationRunnerIntegrationTest extends TestCase
{
    private PdoConnection $connection;
    private string $tempDir;
    private MigrationRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_runner_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);

        $this->repository = new MigrationRepository($this->tempDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function ensureMigrationTableCreatesTable(): void
    {
        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        $runner->ensureMigrationTable();

        // Verify the table exists by querying it
        $result = $this->connection->query('SELECT COUNT(*) as cnt FROM pulsar_migrations');
        self::assertSame(0, $result->firstOrFail()->getInt('cnt'));
    }

    #[Test]
    public function runPendingAppliesMigrations(): void
    {
        $this->createMigrationFile(
            '20240101120000_create_items_table.php',
            <<<'PHP'
                <?php
                declare(strict_types=1);
                use Pulsar\Database\ConnectionInterface;
                use Pulsar\Database\Migration\MigrationInterface;
                return new class implements MigrationInterface {
                    public function up(ConnectionInterface $connection): void {
                        $connection->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
                    }
                    public function down(ConnectionInterface $connection): void {
                        $connection->execute('DROP TABLE IF EXISTS items');
                    }
                };
                PHP,
        );

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        $applied = $runner->runPending();

        self::assertSame(['20240101120000'], $applied);

        // Verify the table was created
        $this->connection->execute('INSERT INTO items (name) VALUES (:name)', ['name' => 'Test']);
        $result = $this->connection->query('SELECT * FROM items');
        self::assertSame(1, $result->rowCount);
    }

    #[Test]
    public function runPendingSkipsAlreadyApplied(): void
    {
        $this->createMigrationFile(
            '20240101120000_first.php',
            $this->createTableMigration('table_a'),
        );

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        $first = $runner->runPending();
        self::assertCount(1, $first);

        // Add a second migration
        $this->createMigrationFile(
            '20240102120000_second.php',
            $this->createTableMigration('table_b'),
        );

        $second = $runner->runPending();
        self::assertSame(['20240102120000'], $second);
    }

    #[Test]
    public function runPendingReturnsEmptyWhenNothingPending(): void
    {
        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        $applied = $runner->runPending();

        self::assertSame([], $applied);
    }

    #[Test]
    public function rollbackLastBatchRollsBackCorrectMigrations(): void
    {
        $this->createMigrationFile(
            '20240101120000_create_alpha.php',
            $this->createTableMigration('alpha'),
        );
        $this->createMigrationFile(
            '20240102120000_create_beta.php',
            $this->createTableMigration('beta'),
        );

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        // Run all — batch 1
        $runner->runPending();

        // Add a third migration and run — batch 2
        $this->createMigrationFile(
            '20240103120000_create_gamma.php',
            $this->createTableMigration('gamma'),
        );
        $runner->runPending();

        // Rollback should only rollback batch 2 (gamma)
        $rolledBack = $runner->rollbackLastBatch();

        self::assertSame(['20240103120000'], $rolledBack);

        // Alpha and beta tables should still exist
        $this->connection->query('SELECT * FROM alpha');
        $this->connection->query('SELECT * FROM beta');
    }

    #[Test]
    public function rollbackLastBatchReturnsEmptyWhenNothingToRollback(): void
    {
        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');
        $runner->ensureMigrationTable();

        $rolledBack = $runner->rollbackLastBatch();

        self::assertSame([], $rolledBack);
    }

    #[Test]
    public function resetRollsBackEverything(): void
    {
        $this->createMigrationFile('20240101120000_first.php', $this->createTableMigration('first_table'));
        $this->createMigrationFile('20240102120000_second.php', $this->createTableMigration('second_table'));

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');
        $runner->runPending();

        $rolledBack = $runner->reset();

        // Should roll back in reverse order
        self::assertSame(['20240102120000', '20240101120000'], $rolledBack);

        // Verify no applied migrations remain
        $applied = $runner->getApplied();
        self::assertSame([], $applied);
    }

    #[Test]
    public function getAppliedReturnsRecords(): void
    {
        $this->createMigrationFile('20240101120000_test.php', $this->createTableMigration('test_table'));

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');
        $runner->runPending();

        $applied = $runner->getApplied();

        self::assertCount(1, $applied);
        self::assertSame('20240101120000', $applied[0]->version);
        self::assertSame('test', $applied[0]->name);
        self::assertSame(1, $applied[0]->batch);
    }

    #[Test]
    public function getPendingReturnsPendingFiles(): void
    {
        $this->createMigrationFile('20240101120000_first.php', $this->createTableMigration('t1'));
        $this->createMigrationFile('20240102120000_second.php', $this->createTableMigration('t2'));

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        // Run only the first
        $runner->runPending();

        // Add a third
        $this->createMigrationFile('20240103120000_third.php', $this->createTableMigration('t3'));

        $pending = $runner->getPending();

        self::assertCount(1, $pending);
        self::assertSame('20240103120000', $pending[0]->version);
    }

    #[Test]
    public function batchNumberIncrements(): void
    {
        $this->createMigrationFile('20240101120000_first.php', $this->createTableMigration('batch_t1'));

        $runner = new MigrationRunner($this->connection, $this->repository, 'pulsar_migrations');

        $runner->runPending();
        self::assertSame(1, $runner->getCurrentBatch());

        $this->createMigrationFile('20240102120000_second.php', $this->createTableMigration('batch_t2'));

        $runner->runPending();
        self::assertSame(2, $runner->getCurrentBatch());
    }

    private function createMigrationFile(string $filename, string $content): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . $filename, $content);
    }

    private function createTableMigration(string $tableName): string
    {
        return <<<PHP
            <?php
            declare(strict_types=1);
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;
            return new class implements MigrationInterface {
                public function up(ConnectionInterface \$connection): void {
                    \$connection->execute('CREATE TABLE {$tableName} (id INTEGER PRIMARY KEY)');
                }
                public function down(ConnectionInterface \$connection): void {
                    \$connection->execute('DROP TABLE IF EXISTS {$tableName}');
                }
            };
            PHP;
    }
}
