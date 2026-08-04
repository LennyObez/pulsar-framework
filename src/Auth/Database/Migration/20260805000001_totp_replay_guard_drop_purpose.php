<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

/**
 * Make a TOTP code redeemable once, and stop one user's traffic evicting another's.
 *
 * Two defects in `auth_totp_replay_guard` as 20260327000001 created it:
 *
 *   1. `purpose` sits in the primary key, so one code is redeemable once *per
 *      purpose*. Measured: a single code was accepted for Login, Setup and StepUp
 *      in the same second. ASVS 2.8.4 requires once within the validity period.
 *
 *   2. The pruning index is on `used_at` alone and the DELETE that uses it carries
 *      no `user_id` predicate, so five unrelated logins evict a victim's blocking
 *      row and the next replay is accepted.
 *
 * This is a separate migration rather than an edit to 20260327000001 because that
 * one has been applied. `CREATE TABLE IF NOT EXISTS` would skip an existing table,
 * leaving `purpose NOT NULL` in place while the guard had stopped writing it —
 * every insert would fail and every second-factor check with it. Nothing in the
 * runner would have reported the divergence: migrations are tracked by path, and
 * the CRC32 in MigrationRepository buckets those paths rather than checksumming
 * their contents.
 *
 * Duplicates must go before the narrower key can exist. With `purpose` in the key
 * a single (user_id, time_step) can hold one row per purpose, and all but one of
 * them violate the new primary key.
 *
 * ## Why every step is idempotent, and why nothing returns early
 *
 * MySQL and SQLite commit each DDL statement as it runs — {@see \Pulsar\Database\Schema\
 * SchemaCapabilities::supportsTransactionalDdl()} says so — while MigrationRunner records
 * a migration only once `up()` has returned. A run that dies partway therefore leaves the
 * schema half-changed and the migration unrecorded, and runs again from the top.
 *
 * An earlier draft opened `up()` with `if (!hasPurposeColumn()) return;`. On the second
 * run that guard was the defect: the column had already gone, so the method returned
 * successfully without ever reaching the index work, and the runner wrote down a success
 * over a table left with no pruning index at all.
 *
 * Moving that guard around the alter branch was not enough, and adversarial review found
 * why: PostgreSQL drops the column and adds the key as two statements, and dropping the
 * column is precisely what falsifies the guard. A run dying between them resumed, skipped
 * the branch, and reported success over a table with **no primary key at all** — the same
 * defect one step to the left, now silently disabling ASVS 2.8.4 rather than losing an
 * index. The same guard also wedged a normal rollback-then-migrate cycle with SQLSTATE
 * 42P16, because the `ADD PRIMARY KEY` ran unconditionally against a key `down()` had
 * correctly left in place.
 *
 * So the rule this file now follows: **a step is guarded by its own postcondition, never
 * by a flag two steps share.** "Does the table have a primary key" gates adding one;
 * "does the table exist" gates touching it at all; the SQLite rebuild is re-enterable from
 * its own midpoint. Nothing here reads a predicate that an earlier statement invalidates.
 */
return new class implements MigrationInterface {
    private const string TABLE = 'auth_totp_replay_guard';
    private const string OLD_INDEX = 'idx_replay_guard_used_at';
    private const string NEW_INDEX = 'idx_replay_guard_user_used_at';

    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

        // Before the refusal, not after it. These two used to test the same predicate,
        // and the refusal won — so the recovery below was unreachable, the interrupted
        // state was permanent, and the advice the refusal gave ("run the creating
        // migration") produced an empty table whose rebuild then destroyed the very rows
        // stranded in the scratch table. Measured: 3 rows in, 0 out, both migrations
        // reporting success.
        $this->completeInterruptedRebuild($connection, $driver);

        if (!$this->tableExists($connection, $driver)) {
            // Not a no-op. A no-op here would let the migration return successfully — and
            // be recorded as applied — over a database with no replay guard at all, which
            // is a worse outcome than stopping. 20260327000001 creates this table; if it
            // is absent, that migration has not run or did not finish.
            throw new RuntimeException(
                'auth_totp_replay_guard is absent: run 20260327000001_create_2fa_tables first',
            );
        }

        $hasPurpose = $this->hasPurposeColumn($connection, $driver);

        // The precondition of "this table has a narrow primary key" is "this table has no
        // duplicate (user_id, time_step)", so the dedup belongs to the key, not to the
        // column. It used to sit inside the branch below — which meant a table that lost
        // its key and then accumulated duplicates could never regain one: every resumed
        // run failed on 23505 forever, and with no key the replay guard was simply off.
        if ($hasPurpose || !$this->hasPrimaryKey($connection, $driver)) {
            $this->removeDuplicateTimeSteps($connection, $driver, $hasPurpose);
        }

        // Guarded per step rather than once at the top: see the class docblock.
        if ($hasPurpose) {
            // The one place this migration still asks which engine it is talking to.
            // Dropping a primary-key column has no portable spelling — SQLite cannot do
            // it at all and rebuilds — and no dialect method covers it. A fourth driver
            // makes this match non-exhaustive, which PHPStan reports rather than leaving
            // to an UnhandledMatchError in production.
            match ($driver) {
                Driver::SQLite => $this->rebuildForSqlite($connection),
                Driver::MySQL => $this->alterForMySql(
                    $connection,
                    $this->hasPrimaryKey($connection, $driver),
                ),
                Driver::PostgreSQL => $this->dropPurposeColumnForPostgreSql($connection),
            };
        }

        // Its own postcondition, not a flag two steps share. PostgreSQL drops the column
        // and adds the key as two statements, and dropping the column is what falsifies
        // hasPurposeColumn() — so a run that died between them used to resume, skip the
        // whole branch, and report success over a table with no primary key at all.
        // Measured: the same (user_id, time_step) accepted twice.
        if (!$this->hasPrimaryKey($connection, $driver)) {
            match ($driver) {
                // SQLite has no `ADD PRIMARY KEY` — it is a syntax error, not a
                // limitation that degrades — so giving the table a key means rebuilding
                // it, which is the same operation the column drop already uses. On the
                // ordinary path the rebuild above has already left a key here and this
                // branch is skipped; it exists for a table that lost one.
                Driver::SQLite => $this->rebuildForSqlite($connection),
                Driver::MySQL, Driver::PostgreSQL => $connection->execute(
                    'ALTER TABLE ' . self::TABLE . ' ADD PRIMARY KEY (user_id, time_step)',
                ),
            };
        }

        $this->replacePruningIndex($connection);
    }

    /**
     * Reinstating `purpose` would reintroduce the weakness, so down() restores the
     * column without restoring it to the key: rolling back must not silently make
     * a code redeemable three times again.
     */
    public function down(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        if (!$this->hasPurposeColumn($connection, $connection->driver())) {
            $connection->execute(
                'ALTER TABLE ' . self::TABLE . " ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT ''",
            );
        }

        // The index pair goes back the way it came, so a rollback followed by a fresh
        // `up()` starts from the shape 20260327000001 leaves behind rather than a hybrid.
        $indexes->dropIfPresent(self::TABLE, self::NEW_INDEX);
        $indexes->ensure(self::TABLE, self::OLD_INDEX, ['used_at']);
    }

    /**
     * Every introspection query below is scoped to the one table the DDL will touch.
     *
     * PostgreSQL uses `to_regclass`, which resolves a bare name exactly as the DDL does,
     * so the answer cannot come from a different schema on the search path. The earlier
     * version asked `information_schema.columns` with no schema predicate at all: on the
     * stock default path (`"$user", public`) with a role owning a same-named schema, it
     * answered for somebody else's table. `to_regclass` returns NULL when there is no
     * such relation, and a comparison against NULL yields no rows, so absence counts as
     * zero without a special case.
     */
    private function tableExists(ConnectionInterface $connection, Driver $driver): bool
    {
        return $this->countOf($connection, match ($driver) {
            Driver::SQLite => "SELECT COUNT(*) AS c FROM sqlite_master "
                . "WHERE type = 'table' AND name = '" . self::TABLE . "'",
            Driver::MySQL => 'SELECT COUNT(*) AS c FROM information_schema.tables '
                . "WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "'",
            Driver::PostgreSQL => "SELECT COUNT(*) AS c FROM pg_class "
                . "WHERE oid = to_regclass('" . self::TABLE . "')",
        });
    }

    private function hasPurposeColumn(ConnectionInterface $connection, Driver $driver): bool
    {
        return $this->countOf($connection, match ($driver) {
            Driver::SQLite => "SELECT COUNT(*) AS c FROM pragma_table_info('" . self::TABLE . "') WHERE name = 'purpose'",
            Driver::MySQL => 'SELECT COUNT(*) AS c FROM information_schema.columns '
                . "WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "' AND column_name = 'purpose'",
            Driver::PostgreSQL => 'SELECT COUNT(*) AS c FROM pg_attribute '
                . "WHERE attrelid = to_regclass('" . self::TABLE . "') "
                . "AND attname = 'purpose' AND NOT attisdropped AND attnum > 0",
        });
    }

    private function hasPrimaryKey(ConnectionInterface $connection, Driver $driver): bool
    {
        return $this->countOf($connection, match ($driver) {
            // `pk` is the 1-based position of a column within the key, and 0 for columns
            // outside it, so any positive value means a key exists.
            Driver::SQLite => "SELECT COUNT(*) AS c FROM pragma_table_info('" . self::TABLE . "') WHERE pk > 0",
            Driver::MySQL => 'SELECT COUNT(*) AS c FROM information_schema.table_constraints '
                . "WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "' "
                . "AND constraint_type = 'PRIMARY KEY'",
            Driver::PostgreSQL => 'SELECT COUNT(*) AS c FROM pg_constraint '
                . "WHERE conrelid = to_regclass('" . self::TABLE . "') AND contype = 'p'",
        });
    }

    private function countOf(ConnectionInterface $connection, string $sql): bool
    {
        foreach ($connection->query($sql)->rows as $row) {
            return $row->getInt('c') > 0;
        }

        return false;
    }

    /**
     * Keep one row per (user_id, time_step). Which one does not matter: the row
     * exists to say "this code has been used", and any of them says it.
     */
    private function removeDuplicateTimeSteps(
        ConnectionInterface $connection,
        Driver $driver,
        bool $hasPurpose,
    ): void {
        if (!$hasPurpose) {
            $this->removeDuplicatesWithoutPurpose($connection, $driver);

            return;
        }

        // While `purpose` is in the key, (user_id, purpose, time_step) is unique, so
        // `keep.purpose < t.purpose` is a total order among the rows sharing a
        // (user_id, time_step) and no two of them can tie. That is what makes these
        // deletes exact rather than approximate.
        $connection->execute(match ($driver) {
            // rowid is guaranteed present: the table has no INTEGER PRIMARY KEY alias.
            Driver::SQLite => <<<'SQL'
                DELETE FROM auth_totp_replay_guard
                WHERE rowid NOT IN (
                    SELECT MIN(rowid) FROM auth_totp_replay_guard GROUP BY user_id, time_step
                )
                SQL,
            // MySQL forbids reading the target table in a subquery, hence the join.
            Driver::MySQL => <<<'SQL'
                DELETE t FROM auth_totp_replay_guard t
                JOIN auth_totp_replay_guard keep
                  ON keep.user_id = t.user_id
                 AND keep.time_step = t.time_step
                 AND keep.purpose < t.purpose
                SQL,
            Driver::PostgreSQL => <<<'SQL'
                DELETE FROM auth_totp_replay_guard t
                USING auth_totp_replay_guard keep
                WHERE keep.user_id = t.user_id
                  AND keep.time_step = t.time_step
                  AND keep.purpose < t.purpose
                SQL,
        });
    }

    /**
     * Deduplicate a table that has already lost `purpose`, where two rows sharing a
     * (user_id, time_step) may be byte-for-byte identical.
     *
     * SQLite and PostgreSQL each expose a physical row identifier — `rowid` and `ctid` —
     * which separates rows nothing else can. **MySQL exposes none**, so identical rows
     * cannot be told apart by any predicate and no `DELETE` can keep exactly one. There
     * the only correct answer is to rebuild the table from a grouped read, which is the
     * same technique SQLite uses for its column drop.
     */
    private function removeDuplicatesWithoutPurpose(ConnectionInterface $connection, Driver $driver): void
    {
        if ($driver === Driver::MySQL) {
            $connection->execute('DROP TABLE IF EXISTS auth_totp_replay_guard_dedup');
            $connection->execute(<<<'SQL'
                CREATE TABLE auth_totp_replay_guard_dedup AS
                SELECT user_id, time_step, MIN(used_at) AS used_at
                FROM auth_totp_replay_guard
                GROUP BY user_id, time_step
                SQL);
            $connection->execute('DROP TABLE auth_totp_replay_guard');
            $connection->execute('RENAME TABLE auth_totp_replay_guard_dedup TO auth_totp_replay_guard');

            return;
        }

        $connection->execute(match ($driver) {
            Driver::SQLite => <<<'SQL'
                DELETE FROM auth_totp_replay_guard
                WHERE rowid NOT IN (
                    SELECT MIN(rowid) FROM auth_totp_replay_guard GROUP BY user_id, time_step
                )
                SQL,
            Driver::PostgreSQL => <<<'SQL'
                DELETE FROM auth_totp_replay_guard t
                USING auth_totp_replay_guard keep
                WHERE keep.user_id = t.user_id
                  AND keep.time_step = t.time_step
                  AND keep.ctid < t.ctid
                SQL,
            Driver::MySQL => '',
        });
    }

    /**
     * SQLite cannot drop a column that belongs to the primary key, at any version.
     * The table is rebuilt instead — the documented approach, and safe here because
     * the rows are short-lived replay markers.
     *
     * The rebuild drops the old table, and its indexes with it, so the index work that
     * follows finds nothing to remove and creates the new index from scratch.
     */
    /**
     * Finish a rebuild that died between `DROP TABLE` and `ALTER TABLE ... RENAME`.
     *
     * In that window the migrated rows live in the scratch table and the real one is
     * gone. Completing the rename is the only recovery that keeps them: every other
     * route — refusing, or re-running the creating migration and rebuilding again —
     * ends with `DROP TABLE IF EXISTS auth_totp_replay_guard_new` destroying them.
     *
     * SQLite only. The other two engines alter in place and never occupy this state.
     */
    private function completeInterruptedRebuild(ConnectionInterface $connection, Driver $driver): void
    {
        if ($driver !== Driver::SQLite) {
            return;
        }

        if ($this->tableExists($connection, $driver) || !$this->scratchTableExists($connection)) {
            return;
        }

        $connection->execute('ALTER TABLE auth_totp_replay_guard_new RENAME TO auth_totp_replay_guard');
    }

    private function scratchTableExists(ConnectionInterface $connection): bool
    {
        return $this->countOf(
            $connection,
            "SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = 'auth_totp_replay_guard_new'",
        );
    }

    private function rebuildForSqlite(ConnectionInterface $connection): void
    {
        // Safe here because up() has already completed any interrupted rename, so a
        // scratch table surviving at this point is a leftover with no rows worth keeping.
        $connection->execute('DROP TABLE IF EXISTS auth_totp_replay_guard_new');

        $connection->execute(<<<'SQL'
            CREATE TABLE auth_totp_replay_guard_new (
                user_id VARCHAR(36) NOT NULL,
                time_step INTEGER NOT NULL,
                used_at TEXT NOT NULL,
                PRIMARY KEY (user_id, time_step)
            )
            SQL);

        $connection->execute(<<<'SQL'
            INSERT INTO auth_totp_replay_guard_new (user_id, time_step, used_at)
            SELECT user_id, time_step, used_at FROM auth_totp_replay_guard
            SQL);

        $connection->execute('DROP TABLE auth_totp_replay_guard');
        $connection->execute('ALTER TABLE auth_totp_replay_guard_new RENAME TO auth_totp_replay_guard');
    }

    /**
     * `DROP PRIMARY KEY` is included only when there is one to drop.
     *
     * A table that reached this point without a key — `down()` restores `purpose` but
     * deliberately not the wide key, so a rollback-then-migrate arrives exactly here —
     * used to meet an unconditional `DROP PRIMARY KEY` and fail with 42000. MySQL applies
     * DDL atomically, so nothing changed and the next run failed identically, forever.
     */
    private function alterForMySql(ConnectionInterface $connection, bool $hasPrimaryKey): void
    {
        $drop = $hasPrimaryKey ? 'DROP PRIMARY KEY, ' : '';

        $connection->execute(
            'ALTER TABLE ' . self::TABLE . ' ' . $drop
            . 'DROP COLUMN purpose, ADD PRIMARY KEY (user_id, time_step)',
        );
    }

    /**
     * Dropping the column takes the primary key with it. Adding the new one is deliberately
     * NOT done here: it is `up()`'s own step, guarded by whether a primary key exists, so
     * that a run dying between the two cannot resume into a table with none.
     */
    private function dropPurposeColumnForPostgreSql(ConnectionInterface $connection): void
    {
        $connection->execute('ALTER TABLE auth_totp_replay_guard DROP COLUMN purpose');
    }

    /**
     * The old index covers `used_at` alone, which is what let a prune scoped to no
     * user delete another user's row.
     *
     * Both halves go through {@see IndexOperations}: written by hand this method said
     * `DROP INDEX` with no existence guard and then `CREATE INDEX IF NOT EXISTS` for
     * every engine, and MySQL rejects both — 1091 and 1064 — after the ALTER above has
     * already committed.
     */
    private function replacePruningIndex(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $indexes->dropIfPresent(self::TABLE, self::OLD_INDEX);
        $indexes->ensure(self::TABLE, self::NEW_INDEX, ['user_id', 'used_at']);
    }
};
