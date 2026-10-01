<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\Migration\MigrationVersionScheme;
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
            if (is_dir($file)) {
                continue;
            }

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

    // =========================================================================
    // Refusing a table written under the pre-1.0.0-rc.12 version scheme
    // =========================================================================

    /**
     * A deploy into a different filesystem path must not re-run applied migrations.
     *
     * Sequential migration versions used to be qualified by a CRC32 of the absolute
     * migrations directory. The row below is what such an installation recorded for
     * `001_create_items.php`; this checkout produces a different string for the same
     * file. Before the guard, `runPending()` saw an unknown version on disk, called it
     * pending, and ran `up()` a second time against a database that already had the
     * table — which for a real migration means dropped columns, duplicated seed rows, or
     * a failed CREATE that aborts the deploy halfway.
     */
    #[Test]
    public function runPendingRefusesWhenTheTableRecordsAMigrationUnderItsOldVersion(): void
    {
        $repository = new MigrationRepository(['ext:acme/shop' => $this->tempDir]);
        $runner = new MigrationRunner($this->connection, $repository, 'pulsar_migrations');

        $this->createMigrationFile('001_create_items.php', $this->createTableMigration('legacy_items'));
        $runner->ensureMigrationTable();
        $this->recordApplied('a3f2_00000000000001', 'create_items');

        try {
            $runner->runPending();
            self::fail('runPending() re-applied a migration the table already records');
        } catch (DatabaseException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('Migration identity mismatch', $message);
        self::assertStringContainsString(
            "UPDATE pulsar_migrations SET version = '"
            . MigrationVersionScheme::prefixForSource('ext:acme/shop')
            . "_00000000000001' WHERE version = 'a3f2_00000000000001';",
            $message,
            'the operator was not told which statement re-keys the row',
        );

        self::assertSame(
            [],
            $this->connection->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'legacy_items'",
            )->rows,
            'the migration ran despite the refusal',
        );
    }

    /**
     * `migrate:status` reads the same list, so it refuses too. A status report that
     * calls an applied migration pending is the report an operator acts on.
     */
    #[Test]
    public function getPendingRefusesRatherThanReportingAnAppliedMigrationAsPending(): void
    {
        $repository = new MigrationRepository(['ext:acme/shop' => $this->tempDir]);
        $runner = new MigrationRunner($this->connection, $repository, 'pulsar_migrations');

        $this->createMigrationFile('001_create_items.php', $this->createTableMigration('status_items'));
        $runner->ensureMigrationTable();
        $this->recordApplied('a3f2_00000000000001', 'create_items');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/Migration identity mismatch/');

        $runner->getPending();
    }

    /**
     * When several sources ship the same number — the ordinary case, since CMS and Forum
     * both start at `001_` — which row recorded which migration is not recoverable from
     * the table. The run still stops: guessing would re-run the one guessed wrong.
     */
    #[Test]
    public function anAmbiguousOldVersionStopsTheRunAndSaysWhyItCannotBeReKeyed(): void
    {
        $forumDir = $this->tempDir . DIRECTORY_SEPARATOR . 'forum';
        mkdir($forumDir, 0o755, true);

        $this->createMigrationFile('001_create_cms.php', $this->createTableMigration('amb_cms'));
        file_put_contents(
            $forumDir . DIRECTORY_SEPARATOR . '001_create_forum.php',
            $this->createTableMigration('amb_forum'),
        );

        $repository = new MigrationRepository([
            'ext:acme/cms' => $this->tempDir,
            'ext:acme/forum' => $forumDir,
        ]);
        $runner = new MigrationRunner($this->connection, $repository, 'pulsar_migrations');

        $runner->ensureMigrationTable();
        $this->recordApplied('a3f2_00000000000001', 'create_cms');

        try {
            $runner->runPending();
            self::fail('an unrecognised version was treated as pending');
        } catch (DatabaseException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('cannot be re-keyed automatically', $message);
        self::assertStringContainsString('a3f2_00000000000001', $message);

        unlink($forumDir . DIRECTORY_SEPARATOR . '001_create_forum.php');
        rmdir($forumDir);
    }

    /**
     * The guard must not fire on the ordinary reasons a recorded version has no file:
     * an extension was uninstalled, or an old migration was deleted after being applied.
     * Neither has an unapplied twin on disk, so neither is a renamed identity.
     */
    #[Test]
    public function aRecordedMigrationWhoseFileIsSimplyGoneDoesNotStopTheRun(): void
    {
        $repository = new MigrationRepository(['ext:acme/shop' => $this->tempDir]);
        $runner = new MigrationRunner($this->connection, $repository, 'pulsar_migrations');

        $this->createMigrationFile('20240101120000_still_here.php', $this->createTableMigration('kept_table'));
        $runner->ensureMigrationTable();
        $this->recordApplied('20230101120000', 'removed_long_ago');

        $applied = $runner->runPending();

        self::assertSame(['20240101120000'], $applied);
    }

    /**
     * A version another source recorded does not stand in for this source's migration.
     *
     * The migrations table keys a migration by version alone; nothing in it says which
     * source shipped the row. So when two sources ship one version — as
     * `src/Auth/Database/Migration`, `extensions/analytics` and
     * `extensions/health-status` all shipped `20260327000001` — the row analytics wrote
     * makes health-status' unrelated migration read as already applied.
     * {@see MigrationRunner::getPending()} subtracts applied versions by key, so it is
     * dropped from the run: `health_check_history` is never created, `migrate` prints
     * nothing pending and exits 0, and the operator finds out at the first write.
     *
     * Measured before the guard existed: source A's `20260327000001` applied, source B
     * enabled in its place, `runPending()` returned `[]` and B's table was absent from
     * `sqlite_master`.
     *
     * The `name` column is what makes it visible without a schema change — it holds the
     * description the applied file carried, so a row whose name is not the name of the
     * file now at that version was written by a different migration.
     */
    #[Test]
    public function aVersionRecordedByAnotherSourceDoesNotSilentlySkipThisOne(): void
    {
        $repository = new MigrationRepository(['ext:acme/health' => $this->tempDir]);
        $runner = new MigrationRunner($this->connection, $repository, 'pulsar_migrations');

        $this->createMigrationFile(
            '20260327000001_create_health_check_history.php',
            $this->createTableMigration('health_check_history'),
        );
        $runner->ensureMigrationTable();

        // Written while a different extension, shipping its own 20260327000001, was installed.
        $this->recordApplied('20260327000001', 'add_missing_fk_indexes');

        try {
            $runner->runPending();
            self::fail(
                'a row another migration wrote was accepted as this migration, so its schema '
                . 'was silently skipped',
            );
        } catch (DatabaseException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('20260327000001', $message);
        self::assertStringContainsString('add_missing_fk_indexes', $message, 'the recorded name is missing');
        self::assertStringContainsString(
            'create_health_check_history',
            $message,
            'the name of the migration on disk is missing',
        );

        self::assertSame(
            [],
            $this->connection->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'health_check_history'",
            )->rows,
            'the migration ran despite the refusal',
        );
    }

    /**
     * The guard reads a name, not a checksum, so nothing fires on a migration that is
     * the one the row recorded.
     */
    #[Test]
    public function aRecordThatNamesTheMigrationOnDiskIsLeftAlone(): void
    {
        $repository = new MigrationRepository(['ext:acme/health' => $this->tempDir]);
        $runner = new MigrationRunner($this->connection, $repository, 'pulsar_migrations');

        $this->createMigrationFile(
            '20260327000001_create_health_check_history.php',
            $this->createTableMigration('untouched_table'),
        );
        $this->createMigrationFile(
            '20260327000002_create_health_incidents.php',
            $this->createTableMigration('health_incidents'),
        );
        $runner->ensureMigrationTable();
        $this->recordApplied('20260327000001', 'create_health_check_history');

        self::assertSame(['20260327000002'], $runner->runPending());
    }

    private function recordApplied(string $version, string $name): void
    {
        $this->connection->execute(
            'INSERT INTO pulsar_migrations (version, name, batch) VALUES (:version, :name, 1)',
            ['version' => $version, 'name' => $name],
        );
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
