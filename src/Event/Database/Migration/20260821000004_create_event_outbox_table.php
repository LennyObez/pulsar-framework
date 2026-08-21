<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaCollation;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Database\Schema\TableIntrospector;

/**
 * The transactional outbox table, moved out of boot and into a version.
 *
 * `outbox_events` was created by `DatabaseOutboxPort::installSchema()`, which `EventWiring`
 * called on every boot of every process wherever the outbox was enabled. Three things were
 * wrong with that, and only the last is about tidiness:
 *
 *   1. It requires the application's own database role to hold CREATE. A framework aimed at
 *      banking and healthcare has to be deployable with a runtime role that reads and writes
 *      rows and can do nothing else; DDL is a change-controlled act, performed by a migration
 *      run, under review, at a moment somebody chose. A process that can create a table can
 *      also be made to create one — so leaving CREATE on the request path turns any
 *      code-execution finding into a schema-modification finding, and leaves the audit
 *      question "who changed this schema, and when" with no answer but "the application, at
 *      some point, on its own authority".
 *   2. It left no trace. Nothing recorded that the outbox schema had been installed, at what
 *      version, or against which database, so the only way to answer "is this database the
 *      shape this build expects" was to go and look. As a migration it is a row in the
 *      migrations table and `migrate:status` answers it.
 *   3. It ran concurrently and repeatedly. Every worker, every FPM child and every console
 *      command raced the same `CREATE TABLE IF NOT EXISTS` at start-up; the clause makes that
 *      harmless rather than correct, and it is a metadata lock per boot for a statement whose
 *      answer is "already done" on all but the very first. {@see \Pulsar\Database\Migration\
 *      MigrationRunner::runPending()} takes an advisory lock and does it once.
 *
 * `DatabaseOutboxPort::installSchema()` and its sibling `migrateSchema()` are deleted in the
 * same change that adds this file, and `EventWiring` no longer calls either, so this migration
 * is the only definition of the table. Leaving one of them in place as a second route to the
 * same schema is what lets two definitions drift, and the outbox already carried the proof:
 * `migrateSchema()` existed to add `dead_lettered_at` to tables that predated it and had no
 * production caller anywhere — only tests reached it — so that upgrade only ever "ran" where
 * `installSchema()` had already created the column on a fresh table. Anyone who installed
 * before the dead-letter column and upgraded afterwards is still missing it. Step 2 of `up()`
 * is that repair, folded in here where it will finally execute.
 *
 * ## One definition, compiled per engine
 *
 * There is no `match ($driver)` here and no engine named anywhere in this file. The table is
 * described once as a {@see TableDefinition} of {@see SchemaColumn}s, and {@see DdlCompiler}
 * — the layer whose job is to know the engines — turns that into the DDL each one accepts.
 * A migration that transcribes three dialects by hand has taken on knowing every engine the
 * framework will ever support, which is the debt {@see \Pulsar\Tests\Unit\Integrity\
 * DriverDispatchRatchetTest} exists to drive down.
 *
 * The definition is built directly rather than through {@see \Pulsar\Database\Schema\
 * SchemaBuilder} and {@see \Pulsar\Database\Schema\Blueprint}. Blueprint's only key-making
 * method is `id()`, which adds a surrogate auto-incrementing column; it cannot nominate an
 * existing column as the primary key, and the key here is the natural one. Adding a surrogate
 * would also be a shape change nobody asked for — the INSERT names its twelve columns
 * explicitly and supplies no id.
 *
 * ## What the compiler cannot express, and what that costs
 *
 * The installer wrote three things by hand that the portable layer had no vocabulary for. It has
 * since gained vocabulary for two, and this migration asks for both by name: {@see
 * SchemaColumnType::BigText} for the `LONGTEXT` payload columns, and {@see SchemaCollation::
 * Exact} for `COLLATE=utf8mb4_bin`, which brings `DEFAULT CHARSET=utf8mb4` with it because MySQL
 * derives a table's character set from its collation. One is left over, and it is not quietly
 * dropped:
 *
 *   - **MySQL `ENGINE=InnoDB`.** The compiler emits no storage engine, so the table takes
 *     `default_storage_engine`. That is InnoDB on every MySQL since 5.5 and every MariaDB
 *     since 10.2, but it is an operator-settable variable: a server configured for MyISAM
 *     would give the outbox a non-transactional table, and the port exists precisely so the
 *     store shares the caller's transaction. A per-table clause could not rescue that
 *     deployment in any case — every table the application creates would have the same
 *     problem, not the five that used to name the engine — so the place to catch it is a
 *     connection preflight. Anyone who has changed that variable must set it back, or convert
 *     the table after migrating.
 *
 * That divergence affects **fresh installs only** — `up()` does not touch a table that already
 * exists, so a host that ran the installer keeps every byte of the shape it was given.
 *
 * `SchemaColumnType::Json` is not what the two payload columns want, and portability is only
 * the first reason ({@see SchemaCapabilities::supportsNativeJson()} is false for MariaDB and
 * SQLite). A MySQL JSON column rejects any string that is not valid JSON, which would turn a
 * malformed payload into a hard INSERT failure instead of the tolerated-and-repaired path the
 * hydrator takes, and it normalises key order and whitespace — which would invalidate
 * `payload_hash`.
 *
 * ## Where the compiled types differ from the installer's, and why that is safe
 *
 * The five string columns were `TEXT` on SQLite and PostgreSQL and bounded `VARCHAR` on MySQL.
 * One type has to serve all three, and it has to be `VARCHAR` — MySQL refuses a `TEXT` column
 * in a key without a prefix length (1170), so `event_id TEXT PRIMARY KEY` cannot be written
 * there at all. The widths are the ones MySQL already enforced: `event_id` 64, `event_type`
 * 255, `payload_hash` 128, `origin_module` 255, `scope` 64.
 *
 *   - SQLite assigns TEXT affinity to any declared type containing `CHAR` and enforces no
 *     length, so those columns behave exactly as they did.
 *   - PostgreSQL stores `VARCHAR(n)` and `TEXT` identically and plans them identically; the
 *     only new behaviour is a length check, at bounds a MySQL deployment already lived within.
 *     An over-long value now raises 22001 instead of being stored.
 *
 * The three timestamps were `TEXT` on SQLite, `DATETIME(6)` on MySQL and `TIMESTAMPTZ` on
 * PostgreSQL. {@see SchemaColumnType::DateTime} keeps `DATETIME(6)` on MySQL — which is what
 * preserves the sub-second FIFO order `pendingEvents()` and `pendingForRelay()` drain by, and
 * whole-second `DATETIME` would collapse — and changes the other two:
 *
 *   - SQLite gets `DATETIME`, which contains none of SQLite's affinity keywords and therefore
 *     takes NUMERIC affinity. NUMERIC converts a text value only when it is a well-formed
 *     integer or real literal, and `2026-08-20 09:00:00.000000` is not, so the value is stored
 *     as TEXT exactly as before and `ORDER BY created_at` still compares fixed-width,
 *     zero-padded strings lexicographically. The declared type changed; nothing else did.
 *   - PostgreSQL gets `TIMESTAMP` rather than `TIMESTAMPTZ`. Every writer binds
 *     `Y-m-d H:i:s.u` with no offset, and nothing reads a timestamp back into PHP — they are
 *     compared to NULL and ordered by, never converted — so the ordering the relay depends on
 *     is identical under either type. What `TIMESTAMPTZ` did was interpret that string in the
 *     server's session zone, which is not the zone PHP formatted it in, and store the result;
 *     a conversion between two zones that need not agree buys nothing here, and storing the
 *     string as written is at least self-consistent.
 *
 * One divergence is a deliberate narrowing. The installer wrote `event_id TEXT PRIMARY KEY`
 * on SQLite, which — there and only there — permits NULL, a documented legacy quirk of
 * non-INTEGER primary keys. The column is declared NOT NULL here, so the compiler emits it and
 * the quirk is closed. It can only reject a row the other two engines already rejected.
 *
 * ## Why the order inside up() is forced
 *
 * The pending index carries the predicate `published_at IS NULL AND dead_lettered_at IS NULL`.
 * A host that installed before the dead-letter column has the table but not that column, so
 * creating the index first raises "no such column: dead_lettered_at" on SQLite and 42703 on
 * PostgreSQL. Table, then column, then indexes — not negotiable.
 *
 * ## Every step is guarded by its own postcondition, and by nothing else
 *
 * The runner wraps `up()` in a transaction, and on two engines of three that transaction is the
 * whole story *while `up()` is running*. MySQL is the exception even there: `CREATE TABLE`,
 * `ALTER TABLE` and `CREATE INDEX` are all on its implicit-commit list, so each lands the moment
 * it runs and no rollback reaches it. A run that dies partway therefore replays from the top
 * over a schema it has already half-changed — on MySQL, and only on MySQL.
 *
 * A second replay reaches this file on every engine, and it is the one no DDL semantics can
 * prevent: `runPending()` writes the applied record *after* the transaction around `up()` has
 * committed, so a process killed in that window leaves a fully-built schema no ledger row
 * mentions and the next run starts here again. PostgreSQL and SQLite arrive at the guards
 * through that door rather than through a half-built table, but they do arrive.
 *
 * So "does the table exist" gates the CREATE and only the CREATE; "does `dead_lettered_at`
 * exist" gates the ALTER and only the ALTER; the indexes are established through
 * {@see IndexOperations}, which asks the catalogue. Put the index work inside the column guard
 * and the replay is the defect: the second run finds the column present, skips the branch,
 * returns successfully, and the runner writes down a success over a table with no indexes at
 * all — the relay then table-scans the outbox on every poll.
 *
 * PostgreSQL and SQLite both undo DDL with that transaction, so writing for MySQL covers them
 * for free; the reverse is not true. SQLite's half of that is measured rather than reasoned
 * about — a `CREATE TABLE`, an `ALTER TABLE ... ADD COLUMN` and a `CREATE INDEX` each vanish
 * again when the enclosing transaction is rolled back — and it had to be measured, because
 * {@see SchemaCapabilities::supportsTransactionalDdl()} used to answer false for SQLite as well
 * as MySQL, so a reader who took that answer for engine behaviour arrived at the opposite of
 * what the engine does. The capability has since been corrected to what the engines actually
 * do, and {@see \Pulsar\Tests\Contract\TransactionalDdlContractTest} re-runs the experiment
 * against each configured server, so the two can no longer disagree in silence. Nothing here
 * rests on it either way: the guards below are written for MySQL, which is the engine that
 * needs them.
 *
 * No try/catch-and-continue would be viable in place of the guards regardless: on PostgreSQL a
 * single failed statement aborts the transaction (25P02) and every statement after it fails as
 * well. SQLite leaves the transaction usable after a failed statement, which is precisely why
 * trying the pattern on SQLite proves nothing about running it anywhere else.
 *
 * ## The indexes, including the one a legacy host has in the wrong shape
 *
 * Never raw `CREATE INDEX IF NOT EXISTS`: MySQL rejects it as a syntax error (1064) rather
 * than degrading, which is exactly how 20260327000001 died mid-file and left second-factor
 * auth uninstalled on that engine. {@see IndexOperations} establishes existence by query on
 * every engine and compiles the spelling each dialect wants.
 *
 * The pending index has two shapes and the choice comes from
 * `dialect()->supportsPartialIndexes()`. Where partial indexes exist the predicate does the
 * filtering and the key is `created_at` alone; where they do not, the dialect drops the WHERE
 * clause, so the filtered columns must lead the key or the index is useless — three columns
 * there, not one. A driver-name branch could not get this right in both directions anyway,
 * because MariaDB shares MySQL's driver, inherits `supportsPartialIndexes() === false` and
 * overrides `supportsIndexIfNotExists()` to true.
 *
 * `ensure()` decides existence by name, and will not rebuild an index whose columns or
 * predicate differ. That is a problem on exactly one class of host: one that installed before
 * the dead-letter column has a `outbox_events_pending_idx` built without `dead_lettered_at`,
 * and leaving it there would mean this migration adds the column while the index that is
 * supposed to use it keeps its old shape. So the pending index is dropped and rebuilt on
 * those hosts — decided by whether the dead-letter index exists, which is a durable fact about
 * the table rather than a flag an earlier step in this same run invalidates. The dead-letter
 * index arrived with the dead-letter column, so its absence says both that this table predates
 * the feature and that any pending index sitting beside it was compiled without it. The check
 * runs before the dead-letter index is created, and self-clears once it is: a resumed run
 * whose first attempt got as far as creating that index does no further rebuilding.
 *
 * What the repair changes is the index's *definition*, and that is the only place it can be
 * observed — which is worth stating, because two obvious ways of looking do not work. Asking
 * whether `outbox_events_pending_idx` exists returns true either way; that is the whole defect.
 * And reading through the port returns the same rows either way, because an index is never
 * what makes a query correct — a stale one merely makes it slower. So the catalogue entry is
 * the assertion. Where the engine has partial indexes, the stale predicate names only
 * `published_at` where the repaired one names `dead_lettered_at` too; where it does not, the
 * stale key is `(published_at, created_at)` against the repaired `(published_at,
 * dead_lettered_at, created_at)`. Both differences are readable from the catalogue — from
 * `sqlite_master.sql`, from `pg_indexes.indexdef`, from `information_schema.statistics` — and
 * a test that reads none of them cannot tell this migration from one with the repair
 * deleted.
 *
 * Both names are already table-prefixed and stay that way. MySQL scopes index names per table,
 * PostgreSQL and SQLite per schema; the prefix is what keeps them from colliding on the latter
 * two. Neither index is UNIQUE — uniqueness on this table comes from the `event_id` primary
 * key and from nowhere else.
 *
 * ## For whoever runs this on a large PostgreSQL outbox
 *
 * Plain `CREATE INDEX` takes a `ShareLock` on `outbox_events` for its duration — measured on
 * PostgreSQL 16 through `pg_locks`, not the ACCESS EXCLUSIVE an earlier draft of this file
 * claimed. The difference decides who waits. `ShareLock` conflicts with the `RowExclusiveLock`
 * every write needs, so the relay's `markPublished()` and `recordFailure()` block, as does
 * every domain transaction trying to store an event — with a `lock_timeout` set they fail with
 * 55P03 rather than queueing. It does not conflict with `AccessShareLock`, so readers are
 * untouched: a concurrent `SELECT` over the table runs throughout. Writes stop; reads do not.
 *
 * `CREATE INDEX CONCURRENTLY` would take neither, and is not available here: it raises 25001
 * inside a transaction block and the runner supplies one. On a fresh install the table is
 * empty and the lock is held for no time at all; on an established outbox, schedule this where
 * a pause in publishing is acceptable.
 *
 * ## down() reverses this migration; it does not delete an outbox
 *
 * On a host that had already run the boot-time installer, `up()` found the table there and
 * changed nothing about it. Dropping it on the way back down would not be reversal — it would
 * be destroying a table this migration never created, and with it every event a domain
 * transaction has committed but nobody has published yet, plus every dead-lettered envelope
 * awaiting triage.
 *
 * So `down()` drops the table only when it is empty, which is the case `up()` is actually
 * responsible for: a fresh install reverses cleanly and completely, indexes included, because
 * `DROP TABLE` takes them on all three engines. A table holding rows makes `down()` refuse and
 * say how many, so the operator drains the outbox and decides — rather than discovering
 * afterwards which events went missing.
 *
 * A refusal on a host where `up()` only added `dead_lettered_at` leaves that column behind.
 * It is nullable, the previous build's INSERT names its columns explicitly, and no reader
 * requires it to be absent, so that schema still serves the build being rolled back to.
 */
return new class implements MigrationInterface {
    private const string TABLE = 'outbox_events';
    private const string PENDING_INDEX = 'outbox_events_pending_idx';
    private const string DEADLETTER_INDEX = 'outbox_events_deadletter_idx';

    public function up(ConnectionInterface $connection): void
    {
        // Order is forced — see the class docblock. The pending index names
        // dead_lettered_at in its predicate, so the column has to exist first.
        $this->createTable($connection);
        $this->addDeadLetteredColumn($connection);
        $this->ensureIndexes($connection);
    }

    /**
     * Reverse a fresh install; refuse to empty a live outbox.
     *
     * The row count is in the message because it is the number the operator needs to
     * decide what to do next, and reading it out of a migration failure is faster than
     * going and asking the database that just refused.
     *
     * @throws RuntimeException When the table holds rows.
     */
    public function down(ConnectionInterface $connection): void
    {
        if (!new TableIntrospector($connection)->tableExists(self::TABLE)) {
            return;
        }

        $rows = $this->rowCount($connection);

        if ($rows > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to drop %s: it holds %d row(s). Those are events already committed '
                . 'by a domain transaction and not yet published, plus any dead-lettered '
                . 'envelopes awaiting triage — dropping the table destroys them rather than '
                . 'reversing this migration. Drain the outbox, then roll back.',
                self::TABLE,
                $rows,
            ));
        }

        // Empty, so nothing is lost, and DROP TABLE takes the indexes with it on all
        // three engines.
        $connection->execute('DROP TABLE ' . self::TABLE);
    }

    /**
     * Create the table, unless it is already there.
     *
     * The compiler emits a bare `CREATE TABLE` — it has no `IF NOT EXISTS` — so existence is
     * established first. That guard covers this step and no other: it is this statement's own
     * postcondition, not a flag the rest of `up()` reads.
     */
    private function createTable(ConnectionInterface $connection): void
    {
        if (new TableIntrospector($connection)->tableExists(self::TABLE)) {
            return;
        }

        foreach ($this->compiler($connection)->compileCreate($this->definition()) as $sql) {
            $connection->execute($sql);
        }
    }

    /**
     * The table, described once.
     *
     * Column order is the installer's, so a `sqlite_master` or `SHOW CREATE TABLE` diff
     * between a migrated database and an installed one has nothing to report but the type
     * differences the class docblock enumerates.
     *
     * `dead_lettered_at` is nullable because the INSERT does not name it, and `origin_module`
     * and `last_error` because neither is known at store time. `publish_attempts` defaults to
     * 0 for the same reason the INSERT can pass a literal 0: the relay increments it.
     *
     * Two columns are {@see SchemaColumnType::BigText} and the rest of the free text is not,
     * which is the installer's own division restated portably. `payload_json` and
     * `metadata_json` hold whatever a domain event carries — a width decided by the data, not
     * by a form — and were `LONGTEXT` for that reason; `last_error` holds a driver's exception
     * message and was plain `TEXT`. On MySQL that is a 4 GiB ceiling against a 65,535-*byte*
     * one, which under the shipped `sql_mode` is error 1406 on the write rather than a
     * truncation, and which a utf8mb4 payload reaches somewhere between 16,383 and 65,535
     * characters. PostgreSQL and SQLite spell all three `TEXT` either way.
     *
     * {@see SchemaCollation::Exact} is the installer's `COLLATE=utf8mb4_bin`, set once on the
     * table so every character column inherits it, exactly as it did there. It is not a sorting
     * preference: `event_id` is the primary key and `markPublished()`, `recordFailure()` and
     * the relay all match `WHERE event_id = :event_id`, so under the case- and
     * accent-insensitive collation MySQL ships as its default, two ids differing only in case
     * would collide on insert and a stamp could land on the wrong row. Framework-generated ids
     * cannot collide that way — {@see \Pulsar\Event\EventEnvelope::wrap()} is `bin2hex()` over
     * 16 random bytes, so they are lowercase hex — but an application supplying its own id
     * through `EventEnvelope::fromArray()` is not held to that. PostgreSQL and SQLite emit no
     * clause and need none: SQLite's default collating sequence is `BINARY`, and every
     * collation `initdb` creates is deterministic, so equality on both falls through to a byte
     * comparison and the key rejects the second spelling.
     */
    private function definition(): TableDefinition
    {
        return new TableDefinition(
            name: self::TABLE,
            collation: SchemaCollation::Exact,
            columns: [
                new SchemaColumn(
                    name: 'event_id',
                    type: SchemaColumnType::String,
                    primaryKey: true,
                    length: 64,
                ),
                new SchemaColumn(name: 'event_type', type: SchemaColumnType::String, length: 255),
                new SchemaColumn(name: 'schema_version', type: SchemaColumnType::Integer),
                new SchemaColumn(name: 'payload_json', type: SchemaColumnType::BigText),
                new SchemaColumn(name: 'payload_hash', type: SchemaColumnType::String, length: 128),
                new SchemaColumn(name: 'metadata_json', type: SchemaColumnType::BigText),
                new SchemaColumn(
                    name: 'origin_module',
                    type: SchemaColumnType::String,
                    nullable: true,
                    length: 255,
                ),
                new SchemaColumn(name: 'scope', type: SchemaColumnType::String, length: 64),
                new SchemaColumn(
                    name: 'publish_attempts',
                    type: SchemaColumnType::Integer,
                    default: 0,
                    hasDefault: true,
                ),
                new SchemaColumn(name: 'last_error', type: SchemaColumnType::Text, nullable: true),
                new SchemaColumn(name: 'published_at', type: SchemaColumnType::DateTime, nullable: true),
                $this->deadLetteredColumn(),
                new SchemaColumn(name: 'created_at', type: SchemaColumnType::DateTime),
            ],
        );
    }

    /**
     * The dead-letter column, defined once and used twice: inline on a fresh table, and as
     * an ALTER on a host whose outbox predates it. Two spellings of one column is how the
     * two drift.
     */
    private function deadLetteredColumn(): SchemaColumn
    {
        return new SchemaColumn(
            name: 'dead_lettered_at',
            type: SchemaColumnType::DateTime,
            nullable: true,
        );
    }

    /**
     * The dead-letter upgrade, folded in from a `migrateSchema()` that nothing ever called.
     *
     * A fresh install gets the column from the `CREATE TABLE` above and skips this. It runs
     * only on a host whose outbox table predates the dead-letter column — where, until now,
     * nothing was ever going to add it.
     *
     * Guarded by its own postcondition and nothing else, and the index work that follows sits
     * outside this guard deliberately: on MySQL the ALTER commits by itself whatever the
     * enclosing transaction does, so a run dying immediately after it replays, finds the
     * column present, and would skip straight past index creation if the two shared a branch.
     */
    private function addDeadLetteredColumn(ConnectionInterface $connection): void
    {
        if (new TableIntrospector($connection)->columnExists(self::TABLE, 'dead_lettered_at')) {
            return;
        }

        $statements = $this->compiler($connection)
            ->compileAlterAddColumn(self::TABLE, $this->deadLetteredColumn());

        foreach ($statements as $sql) {
            $connection->execute($sql);
        }
    }

    /**
     * Two indexes, one shape each — except where the engine cannot express the narrowing.
     *
     * The pending index answers "what has nobody published yet", which on a healthy outbox is
     * a small minority of a table that only grows. A partial index over `created_at` is that
     * question exactly. Without partial indexes the dialect discards the predicate, so the two
     * filtered columns must lead the key instead or the planner gains nothing — hence three
     * columns there and one here, chosen by capability rather than by engine name.
     *
     * The rebuild in front of it is for hosts that installed before `dead_lettered_at`
     * existed, whose pending index was compiled without it. `ensure()` matches on the name and
     * would leave that shape in place, so the drop is what makes the repair real rather than
     * claimed. See the class docblock for why the dead-letter index is the right thing to test,
     * why the ordering below is what makes a resumed run converge, and where the repair can be
     * observed.
     */
    private function ensureIndexes(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        $this->dropStalePendingIndex($indexes);

        $indexes->ensure(
            self::TABLE,
            self::PENDING_INDEX,
            $connection->dialect()->supportsPartialIndexes()
                ? ['created_at']
                : ['published_at', 'dead_lettered_at', 'created_at'],
            where: 'published_at IS NULL AND dead_lettered_at IS NULL',
        );

        $indexes->ensure(
            self::TABLE,
            self::DEADLETTER_INDEX,
            ['dead_lettered_at'],
            where: 'dead_lettered_at IS NOT NULL',
        );
    }

    /**
     * Remove a pending index that was compiled before `dead_lettered_at` existed.
     *
     * The one step of `up()` whose effect nothing else would reveal. It leaves the index name
     * unchanged, the row counts unchanged and every query the port issues answering exactly as
     * it did — all it changes is the shape the catalogue records for
     * `outbox_events_pending_idx`, so that {@see ensureIndexes()} rebuilds it around the column
     * the ALTER has just added. Delete this method and a suite that only asks whether the index
     * exists, or only reads events back through the port, stays green over a stale index.
     *
     * The absence of the dead-letter index is the signal, and it has to be, because the two
     * closer-looking signals do not survive contact with a replay: `dead_lettered_at` is
     * present by the time this runs, having been added one step earlier in this same `up()`,
     * and no in-memory flag outlives the run that set it. The dead-letter index arrived with
     * the dead-letter column, so its absence says both that the table predates the feature and
     * that anything named `outbox_events_pending_idx` beside it was compiled without the
     * column. It is also self-clearing: the moment the dead-letter index is created this stops
     * firing, so an upgraded table is never rebuilt twice, and a fresh install — where neither
     * index exists yet — pays two catalogue lookups and drops nothing.
     */
    private function dropStalePendingIndex(IndexOperations $indexes): void
    {
        if ($indexes->exists(self::TABLE, self::DEADLETTER_INDEX)) {
            return;
        }

        $indexes->ensureAbsent(self::TABLE, self::PENDING_INDEX);
    }

    /**
     * The compiler, built from what the connection already knows about itself.
     *
     * The variant is passed rather than left to default: MariaDB and Percona both arrive
     * through the MySQL driver and are told apart by their `VERSION()` string, and a
     * capability answered for the wrong family member is one this migration would then act on.
     */
    private function compiler(ConnectionInterface $connection): DdlCompiler
    {
        return new DdlCompiler(
            $connection->driver(),
            new SchemaCapabilities($connection->driver(), $connection, $connection->variant()),
        );
    }

    private function rowCount(ConnectionInterface $connection): int
    {
        foreach ($connection->query('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }
};
