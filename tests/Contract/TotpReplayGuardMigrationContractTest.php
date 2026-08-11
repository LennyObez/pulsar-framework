<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Throwable;

use function dirname;
use function sprintf;

/**
 * The two 2FA migrations, run as files, against every engine.
 *
 * `20260805000001_totp_replay_guard_drop_purpose` exists because `purpose` in the primary
 * key made one code redeemable once per purpose — measured: the same code accepted for
 * Login, Setup and StepUp in the same second — and because a prune index on `used_at`
 * alone let unrelated traffic evict a victim's blocking row.
 *
 * ## Why these tests `require` the migration files
 *
 * An earlier version of this test re-implemented both migrations inline, with the table
 * name rebound so runs could not collide. It passed on all three engines against a
 * migration that threw on MySQL, because the copy had quietly left out
 * `replacePruningIndex()` — the only method that failed. A test that reproduces the code
 * under test cannot disagree with it; it can only disagree with the copy. So these run
 * the real files, on the real table names, and drop them afterwards.
 *
 * The fixture carries duplicates on purpose. With `purpose` in the key a single
 * (user_id, time_step) can hold three rows, and every one but the first violates the
 * narrower key — so a migration that forgets to deduplicate fails here rather than on
 * someone's production database.
 */
final class TotpReplayGuardMigrationContractTest extends TestCase
{
    private const string TABLE = 'auth_totp_replay_guard';
    private const string OLD_INDEX = 'idx_replay_guard_used_at';
    private const string NEW_INDEX = 'idx_replay_guard_user_used_at';

    private ?ConnectionInterface $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ([self::TABLE . '_new', self::TABLE, 'auth_recovery_codes', 'auth_totp_secrets'] as $table) {
                $this->connection->execute(sprintf('DROP TABLE IF EXISTS %s', $table));
            }
        }

        $this->connection = null;
    }

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function engines(): iterable
    {
        foreach (DatabaseEngine::all() as $driver) {
            yield $driver->value => [$driver];
        }
    }

    /**
     * The headline for MySQL: the creating migration used to die on its second index and
     * never reach the third table, so the replay guard did not exist and the second
     * factor could not work at all.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theCreatingMigrationCreatesAllThreeTablesAndTheirIndexes(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->creatingMigration()->up($connection);

        $indexes = new IndexOperations($connection);

        self::assertTrue($this->tableExists($connection, $driver, 'auth_totp_secrets'));
        self::assertTrue($this->tableExists($connection, $driver, 'auth_recovery_codes'));
        self::assertTrue(
            $this->tableExists($connection, $driver, self::TABLE),
            'the replay guard table is the one that came after the failing index statement',
        );

        self::assertTrue($indexes->exists('auth_recovery_codes', 'idx_recovery_codes_user'));
        self::assertTrue($indexes->exists('auth_recovery_codes', 'idx_recovery_codes_user_hash'));
        self::assertTrue($indexes->exists(self::TABLE, self::OLD_INDEX));
    }

    #[Test]
    #[DataProvider('engines')]
    public function theCreatingMigrationCanBeRunTwice(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->creatingMigration()->up($connection);
        $this->creatingMigration()->up($connection);

        self::assertTrue(new IndexOperations($connection)->exists(self::TABLE, self::OLD_INDEX));
    }

    #[Test]
    #[DataProvider('engines')]
    public function theMigrationRunsAndLeavesOneRowPerUserAndTimeStep(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);

        // Three purposes, one time step, one user: the shape that made a single code
        // redeemable three times, and the shape that breaks a careless migration.
        $this->insert($connection, 'user-a', 'login', 1000);
        $this->insert($connection, 'user-a', 'setup', 1000);
        $this->insert($connection, 'user-a', 'stepup', 1000);
        $this->insert($connection, 'user-a', 'login', 1001);
        $this->insert($connection, 'user-b', 'login', 1000);

        $this->migration()->up($connection);

        self::assertSame(
            3,
            $this->rowCount($connection),
            'expected (user-a,1000), (user-a,1001) and (user-b,1000) to survive as one row each',
        );

        self::assertFalse(
            $this->hasPurposeColumn($connection, $driver),
            'purpose must be gone: it is what made one code redeemable per purpose',
        );
    }

    /**
     * The pruning index is half the fix, and the half no earlier test exercised.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theMigrationReplacesThePruningIndex(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);

        $indexes = new IndexOperations($connection);
        self::assertTrue($indexes->exists(self::TABLE, self::OLD_INDEX), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertTrue(
            $indexes->exists(self::TABLE, self::NEW_INDEX),
            'the prune must be able to scope by user, which is what the new index is for',
        );
        self::assertFalse(
            $indexes->exists(self::TABLE, self::OLD_INDEX),
            'the used_at-only index is what let one user\'s traffic evict another\'s row',
        );
    }

    /**
     * The defect that made this worse than a plain failure.
     *
     * MySQL and SQLite commit each DDL statement as it runs, and the runner records a
     * migration only once `up()` returns. A first run that died on the index left the
     * column already dropped; the second run then found no `purpose`, returned early,
     * and was written down as applied over a table with no pruning index at all.
     *
     * This reproduces that interrupted state directly and demands the resumed run finish
     * the job.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aRunInterruptedAfterTheAlterIsCompletedByTheNext(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);
        $this->insert($connection, 'user-a', 'login', 4000);

        // Exactly what a run that died inside replacePruningIndex() leaves behind: the
        // column gone, the old index gone, the new one never created.
        $this->migration()->up($connection);
        (void) new IndexOperations($connection)->dropIfPresent(self::TABLE, self::NEW_INDEX);

        self::assertFalse($this->hasPurposeColumn($connection, $driver), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertTrue(
            new IndexOperations($connection)->exists(self::TABLE, self::NEW_INDEX),
            'a resumed run must finish the index work rather than report success without it',
        );
    }

    /**
     * The shape, read from the engine's own catalogue.
     *
     * Without this a mutant widening the key to `(user_id, time_step, used_at)` passed
     * every test here — 24 of 24 — while the real guard then accepted one code once per
     * wall-clock second for the whole of its 90-second envelope. A mutant building the
     * pruning index on `used_at` alone passed too, because `exists()` matches on the name
     * and nothing asked what the index covered.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theKeyAndTheIndexCoverExactlyTheColumnsClaimed(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);
        $this->migration()->up($connection);

        self::assertSame(
            ['user_id', 'time_step'],
            $this->primaryKeyColumns($connection, $driver),
            'a wider key makes the same code redeemable once per distinct extra value',
        );

        self::assertSame(
            ['user_id', 'used_at'],
            $this->indexColumns($connection, $driver, self::NEW_INDEX),
            'the prune scopes by user_id, so the index has to lead with it',
        );
    }

    /**
     * A rollback followed by a fresh migrate is an ordinary operator sequence, and it used
     * to wedge PostgreSQL permanently: `down()` correctly leaves the narrow key in place,
     * and `up()` then ran an unconditional ADD PRIMARY KEY into it — SQLSTATE 42P16, on
     * every retry, with every later migration stuck behind it.
     */
    #[Test]
    #[DataProvider('engines')]
    public function rollingBackAndMigratingAgainIsNotAOneWayDoor(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);

        $migration = $this->migration();
        $migration->up($connection);
        $migration->down($connection);
        $migration->up($connection);

        self::assertSame(['user_id', 'time_step'], $this->primaryKeyColumns($connection, $driver));
        self::assertFalse($this->hasPurposeColumn($connection, $driver));
    }

    /**
     * The state a run leaves behind when it dies between dropping the column and adding
     * the key — two separate statements on PostgreSQL, and dropping the column is exactly
     * what falsifies the guard that used to wrap them both. The resumed run skipped the
     * branch, did the index work, returned successfully, and the runner recorded it over
     * a table with no primary key at all.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aRunThatDiedBeforeTheKeyWasAddedIsCompletedByTheNext(Driver $driver): void
    {
        if ($driver !== Driver::PostgreSQL) {
            // Only PostgreSQL splits the change across two statements. MySQL does it in
            // one ALTER and SQLite rebuilds the table with the key already on it, so
            // neither can occupy this state.
            self::markTestSkipped('only the PostgreSQL path drops the column and adds the key separately');
        }

        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);
        $this->migration()->up($connection);

        // Reproduce the interruption: the column already gone, the key not yet there.
        $connection->execute('ALTER TABLE ' . self::TABLE . ' DROP CONSTRAINT ' . self::TABLE . '_pkey');
        self::assertSame([], $this->primaryKeyColumns($connection, $driver), 'fixture precondition');

        $this->migration()->up($connection);

        self::assertSame(
            ['user_id', 'time_step'],
            $this->primaryKeyColumns($connection, $driver),
            'a resumed run must restore the key, not report success without it',
        );
    }

    /**
     * The degraded state that used to be permanent: no primary key, and duplicates that
     * accumulated while there was none.
     *
     * With no key the replay guard is off — `markUsed()` finds no violation to catch and
     * returns true for every code — so the table manufactures the very duplicates that
     * then block its repair. The dedup used to sit inside the `purpose` branch, so a
     * table whose `purpose` had already gone could never regain a key: every resumed run
     * failed on a duplicate-key violation, forever, and every migration behind it stalled.
     *
     * The duplicates here are byte-for-byte identical on purpose. SQLite and PostgreSQL
     * can separate them by physical row id; MySQL exposes none, which is why its path
     * rebuilds the table from a grouped read instead of deleting.
     */
    #[Test]
    #[DataProvider('engines')]
    public function aTableWithNoKeyAndExactDuplicatesIsRepaired(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);
        $this->migration()->up($connection);

        $this->dropPrimaryKey($connection, $driver);
        self::assertSame([], $this->primaryKeyColumns($connection, $driver), 'fixture precondition');

        foreach ([0, 1, 2] as $_) {
            $connection->execute(
                sprintf('INSERT INTO %s (user_id, time_step, used_at) VALUES (:u, :t, :w)', self::TABLE),
                ['u' => 'victim', 't' => 900, 'w' => '2026-08-06 09:00:00'],
            );
        }

        $this->migration()->up($connection);

        self::assertSame(
            ['user_id', 'time_step'],
            $this->primaryKeyColumns($connection, $driver),
            'the key must be regained, or the replay guard stays off for good',
        );
        self::assertSame(1, $this->rowCount($connection), 'exactly one marker must survive');
    }

    /**
     * Refusing is the point. A no-op would let this return successfully — and be recorded
     * as applied — over a database with no replay guard at all.
     */
    #[Test]
    #[DataProvider('engines')]
    public function itRefusesToRunWithoutTheTableItMigrates(Driver $driver): void
    {
        $connection = $this->engine($driver);

        $this->expectExceptionMessageMatches('/auth_totp_replay_guard is absent/');

        $this->migration()->up($connection);
    }

    /**
     * The narrower key must be enforced by the engine, not merely intended.
     */
    #[Test]
    #[DataProvider('engines')]
    public function theEngineRefusesASecondRowForTheSameUserAndTimeStep(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);
        $this->insert($connection, 'user-a', 'login', 2000);

        $this->migration()->up($connection);

        $rejected = false;

        try {
            // A different used_at from the fixture's, deliberately: sharing the literal
            // let a mutant widening the key to (user_id, time_step, used_at) pass this
            // test, because the two rows then collided on the wider key as well.
            $connection->execute(
                sprintf('INSERT INTO %s (user_id, time_step, used_at) VALUES (:u, :t, :w)', self::TABLE),
                ['u' => 'user-a', 't' => 2000, 'w' => '2026-08-05 12:00:29'],
            );
        } catch (Throwable) {
            $rejected = true;
        }

        self::assertTrue($rejected, 'a replayed (user_id, time_step) must violate the primary key');
    }

    #[Test]
    #[DataProvider('engines')]
    public function theMigrationIsIdempotent(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);
        $this->insert($connection, 'user-a', 'login', 3000);

        $this->migration()->up($connection);
        $this->migration()->up($connection);

        self::assertSame(1, $this->rowCount($connection));
        self::assertFalse($this->hasPurposeColumn($connection, $driver));
        self::assertTrue(new IndexOperations($connection)->exists(self::TABLE, self::NEW_INDEX));
    }

    /**
     * Rolling back restores the column but never the key, and puts the index pair back
     * the way the creating migration leaves it.
     */
    #[Test]
    #[DataProvider('engines')]
    public function downRestoresTheColumnAndTheOriginalIndex(Driver $driver): void
    {
        $connection = $this->engine($driver);
        $this->creatingMigration()->up($connection);

        $migration = $this->migration();
        $migration->up($connection);
        $migration->down($connection);

        $indexes = new IndexOperations($connection);

        self::assertTrue($this->hasPurposeColumn($connection, $driver));
        self::assertTrue($indexes->exists(self::TABLE, self::OLD_INDEX));
        self::assertFalse($indexes->exists(self::TABLE, self::NEW_INDEX));

        // The key must stay narrow: a rollback that restored purpose to the primary key
        // would make one code redeemable three times again.
        $this->insert($connection, 'user-a', 'login', 5000);
        $rejected = false;

        try {
            $this->insert($connection, 'user-a', 'setup', 5000);
        } catch (Throwable) {
            $rejected = true;
        }

        self::assertTrue($rejected, 'down() must not put purpose back into the primary key');
    }

    // --- the migrations, as files ---

    private function migration(): MigrationInterface
    {
        /** @var MigrationInterface */
        return require dirname(__DIR__, 2)
            . '/src/Auth/Database/Migration/20260805000001_totp_replay_guard_drop_purpose.php';
    }

    private function creatingMigration(): MigrationInterface
    {
        /** @var MigrationInterface */
        return require dirname(__DIR__, 2)
            . '/src/Auth/Database/Migration/20260327000001_create_2fa_tables.php';
    }

    // --- fixture ---

    private function insert(ConnectionInterface $connection, string $user, string $purpose, int $timeStep): void
    {
        $connection->execute(
            sprintf('INSERT INTO %s (user_id, purpose, time_step, used_at) VALUES (:u, :p, :t, :w)', self::TABLE),
            ['u' => $user, 'p' => $purpose, 't' => $timeStep, 'w' => '2026-08-05 12:00:00'],
        );
    }

    private function rowCount(ConnectionInterface $connection): int
    {
        foreach ($connection->query(sprintf('SELECT COUNT(*) AS c FROM %s', self::TABLE))->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }

    /**
     * SQLite cannot drop a primary key, so the table is rebuilt without one — which is
     * exactly the shape an interrupted rebuild would leave.
     */
    private function dropPrimaryKey(ConnectionInterface $connection, Driver $driver): void
    {
        match ($driver) {
            Driver::MySQL => $connection->execute('ALTER TABLE ' . self::TABLE . ' DROP PRIMARY KEY'),
            Driver::PostgreSQL => $connection->execute(
                'ALTER TABLE ' . self::TABLE . ' DROP CONSTRAINT ' . self::TABLE . '_pkey',
            ),
            Driver::SQLite => (function () use ($connection): void {
                $connection->execute(
                    'CREATE TABLE keyless (user_id VARCHAR(36) NOT NULL, '
                    . 'time_step INTEGER NOT NULL, used_at TEXT NOT NULL)',
                );
                $connection->execute(
                    'INSERT INTO keyless (user_id, time_step, used_at) '
                    . 'SELECT user_id, time_step, used_at FROM ' . self::TABLE,
                );
                $connection->execute('DROP TABLE ' . self::TABLE);
                $connection->execute('ALTER TABLE keyless RENAME TO ' . self::TABLE);
            })(),
        };
    }

    /**
     * @return list<string> Ordered as the key declares them
     */
    private function primaryKeyColumns(ConnectionInterface $connection, Driver $driver): array
    {
        return $this->columnList($connection, match ($driver) {
            Driver::SQLite => "SELECT name AS col FROM pragma_table_info('" . self::TABLE . "') "
                . 'WHERE pk > 0 ORDER BY pk',
            Driver::MySQL => 'SELECT column_name AS col FROM information_schema.key_column_usage '
                . "WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "' "
                . "AND constraint_name = 'PRIMARY' ORDER BY ordinal_position",
            Driver::PostgreSQL => 'SELECT a.attname AS col FROM pg_index x '
                . 'CROSS JOIN LATERAL unnest(x.indkey) WITH ORDINALITY AS k(attnum, ord) '
                . 'JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = k.attnum '
                . "WHERE x.indrelid = to_regclass('" . self::TABLE . "') AND x.indisprimary ORDER BY k.ord",
        });
    }

    /**
     * @return list<string> Ordered as the index declares them
     */
    private function indexColumns(ConnectionInterface $connection, Driver $driver, string $index): array
    {
        return $this->columnList($connection, match ($driver) {
            Driver::SQLite => "SELECT name AS col FROM pragma_index_info('" . $index . "') ORDER BY seqno",
            Driver::MySQL => 'SELECT column_name AS col FROM information_schema.statistics '
                . "WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "' "
                . "AND index_name = '" . $index . "' ORDER BY seq_in_index",
            Driver::PostgreSQL => 'SELECT a.attname AS col FROM pg_index x '
                . 'JOIN pg_class i ON i.oid = x.indexrelid '
                . 'CROSS JOIN LATERAL unnest(x.indkey) WITH ORDINALITY AS k(attnum, ord) '
                . 'JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = k.attnum '
                . "WHERE x.indrelid = to_regclass('" . self::TABLE . "') "
                . "AND i.relname = '" . $index . "' ORDER BY k.ord",
        });
    }

    /**
     * @return list<string>
     */
    private function columnList(ConnectionInterface $connection, string $sql): array
    {
        $columns = [];

        foreach ($connection->query($sql)->rows as $row) {
            $columns[] = $row->getString('col');
        }

        return $columns;
    }

    private function tableExists(ConnectionInterface $connection, Driver $driver, string $table): bool
    {
        $sql = match ($driver) {
            Driver::SQLite => "SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = :t",
            Driver::MySQL => 'SELECT COUNT(*) AS c FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :t',
            Driver::PostgreSQL => 'SELECT COUNT(*) AS c FROM information_schema.tables '
                . 'WHERE table_schema = ANY (current_schemas(false)) AND table_name = :t',
        };

        foreach ($connection->query($sql, ['t' => $table])->rows as $row) {
            return $row->getInt('c') > 0;
        }

        return false;
    }

    private function hasPurposeColumn(ConnectionInterface $connection, Driver $driver): bool
    {
        $sql = match ($driver) {
            Driver::SQLite => sprintf(
                "SELECT COUNT(*) AS c FROM pragma_table_info('%s') WHERE name = 'purpose'",
                self::TABLE,
            ),
            Driver::MySQL => 'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() '
                . "AND table_name = '" . self::TABLE . "' AND column_name = 'purpose'",
            Driver::PostgreSQL => 'SELECT COUNT(*) AS c FROM information_schema.columns '
                . "WHERE table_name = '" . self::TABLE . "' AND column_name = 'purpose'",
        };

        foreach ($connection->query($sql)->rows as $row) {
            return $row->getInt('c') > 0;
        }

        return false;
    }

    private function engine(Driver $driver): ConnectionInterface
    {
        if (!DatabaseEngine::isConfigured($driver)) {
            self::markTestSkipped(DatabaseEngine::absenceReason($driver));
        }

        $this->connection = DatabaseEngine::connect($driver);

        // A previous run that died mid-test leaves tables behind, and the creating
        // migration's CREATE TABLE IF NOT EXISTS would silently adopt them.
        foreach ([self::TABLE . '_new', self::TABLE, 'auth_recovery_codes', 'auth_totp_secrets'] as $table) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        return $this->connection;
    }
}
