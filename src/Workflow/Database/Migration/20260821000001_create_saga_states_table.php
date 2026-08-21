<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaCollation;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Database\Schema\TableIntrospector;

/**
 * The table durable sagas resume from — created by a migration, not by the application.
 *
 * `saga_states` holds one row per saga, upserted by id, and is what lets an interrupted
 * saga be resumed after a process restart. Until now its DDL lived in
 * {@see \Pulsar\Workflow\Internal\Storage\DatabaseSagaStateStorage}`::installSchema()`, and
 * nothing in the framework ever called that method: `SagaWiring` constructs the storage and
 * binds it as the `SagaStateStorageInterface` port whenever a `ConnectionManagerInterface`
 * is bound and the application has not supplied a port of its own, and `SagaWiring` is
 * registered unconditionally in `WiringList::default()`. So every such boot bound a durable
 * saga store against a table no code path had ever created, and the first
 * `SagaStateStorageInterface::save()` — which `SagaOrchestrator::start()` makes before the
 * saga runs a single step — failed on a missing table. The one caller of `installSchema()`
 * in the whole repository was the storage's own unit test, which is why the gap survived:
 * the test created the table it then tested against.
 *
 * ## Why the installer goes away rather than being called from wiring
 *
 * The obvious repair — call `installSchema()` from `SagaWiring` — is the shape the event
 * outbox already had, where `EventWiring` installed its schema at boot. That is the wrong
 * posture for the deployments this framework targets. Running DDL from the application
 * means the credentials the request path uses must hold CREATE, and a role that can CREATE
 * can also DROP and ALTER: an injection or a deserialisation bug stops being a data-read
 * problem and becomes a schema problem, and the separation auditors expect between the role
 * that changes the schema and the role that reads and writes rows is gone. It also puts DDL
 * on the boot path of every process — every worker, every CLI invocation, every request
 * under a process manager that recycles — where a lock wait or an interrupted statement is
 * an outage rather than a failed deploy step. Schema changes belong to a deploy step run by
 * a migration role; the application role needs INSERT, UPDATE, SELECT and nothing else.
 *
 * `DatabaseSagaStateStorage::installSchema()` is deleted rather than left in place beside
 * this file. Two definitions of one table drift — silently, because nothing compares them —
 * and the one that drifts is whichever the tests do not exercise.
 *
 * ## Compiled, not transcribed
 *
 * An earlier draft of this file wrote all three dialects by hand behind `match ($driver)`.
 * {@see \Pulsar\Database\Driver} answers exactly one question — which PDO driver opened this
 * connection — and a caller that branches on the answer has taken on a second job: knowing
 * every engine the framework will ever support. That is the job that makes adding an engine
 * a breaking change rather than an additive one, and
 * `tests/Unit/Integrity/DriverDispatchRatchetTest` enforces its retirement as a ratchet: a
 * file under `src/` outside `src/Database/` that names a `Driver` case must appear in the
 * baseline, and the baseline may only shrink.
 *
 * So the table is described once, as a {@see TableDefinition} of {@see SchemaColumn} values,
 * and {@see DdlCompiler} spells that description for whichever engine answered. Identifier
 * quoting, type mapping, the placement of the primary-key constraint and the one table
 * option only MySQL has a clause for are all its work. MariaDB reaches the same mapping
 * MySQL does, as it did in the installer.
 *
 * The key is declared as a `primaryKey: true` column rather than through
 * {@see \Pulsar\Database\Schema\Blueprint}, whose only vocabulary for a primary key is
 * `id()` — an auto-incrementing BIGINT, which this table does not have.
 * {@see TableDefinition} and {@see SchemaColumn} express it directly and are public API.
 *
 * ## Where the compiled shape differs from the installed one
 *
 * Hosts that ran `installSchema()`, by hand or through a deploy script of their own, already
 * have this table, and `up()` leaves a table that already exists alone. So every difference
 * below lands on fresh installs only. Each one is stated because a silent divergence is how
 * one fleet comes to run two incompatible shapes of the same table with every host reporting
 * the migration applied — and nothing would catch it later either: migrations are tracked by
 * path and version, and the CRC32 in `MigrationRepository` buckets directory paths rather
 * than hashing file contents, so there is no checksum to notice a difference.
 *
 * This list used to open with two entries that are no longer here, and they were the two
 * with teeth. `LONGTEXT` narrowed to `TEXT` on MySQL, and the whole table-options clause
 * dropped, were not shortfalls of this migration but of the vocabulary it had: the schema
 * layer held one text type and no table options at all. Both gaps are now closed in
 * {@see \Pulsar\Database\Schema} — {@see SchemaColumnType::BigText} and
 * {@see SchemaCollation::Exact} — and this file asks for both, so those two rows of the
 * installer's MySQL DDL are reproduced rather than approximated, and every future table
 * inherits the same vocabulary instead of re-deriving the workaround. What follows is what
 * is genuinely left.
 *
 * **`ENGINE=InnoDB` is not emitted, and nothing follows from that.** Of the three MySQL
 * table options the installer set, `COLLATE=utf8mb4_bin` is now emitted from the
 * {@see TableDefinition}, and `DEFAULT CHARSET=utf8mb4` does not need to be: MySQL derives a
 * table's character set from its collation when only the collation is given, and
 * `utf8mb4_bin` belongs to utf8mb4, so the resulting table carries the character set the
 * installer named. `ENGINE=InnoDB` is the one clause that really is absent, and it changed
 * nothing — InnoDB has been the default storage engine since MySQL 5.5 and MariaDB 10.0, and
 * a server configured to default elsewhere would be creating non-transactional tables for
 * the entire application rather than for this one, which is a connection-level problem a
 * per-table clause cannot rescue. It did not buy a row format either, which an earlier draft
 * of this file claimed: row format comes from `innodb_default_row_format` — DYNAMIC since
 * MySQL 5.7.9 and MariaDB 10.2.2 — or from an explicit `ROW_FORMAT` clause, and
 * `ENGINE=InnoDB` selects neither. A `VARCHAR(255)` utf8mb4 key is 1020 bytes, which fits
 * DYNAMIC's 3072-byte index limit and exceeds the 767 bytes COMPACT and REDUNDANT allow; on
 * a server configured back to one of those the `CREATE TABLE` fails with error 1071 —
 * exactly as it would have under the installer, which pinned the charset but never the row
 * format.
 *
 * The clause that is emitted is worth spelling out, because it decides whether two saga ids
 * are one row or two. `utf8mb4_bin` makes `saga_id` comparison
 * byte-exact; without it the column takes the server default — `utf8mb4_0900_ai_ci` on
 * MySQL 8, `utf8mb4_general_ci` on MariaDB — both of which fold case and accents, and since
 * `save()` upserts with `ON DUPLICATE KEY UPDATE`, two ids that fold together silently
 * overwrite one another's persisted state with no error anywhere. Ids the framework issues
 * itself were never exposed — `SagaOrchestrator` uses `bin2hex()` over sixteen random bytes,
 * thirty-two lowercase hex characters, and no folding maps two distinct such strings
 * together — but an application supplying its own ids is, and that is the case the clause
 * covers. PostgreSQL and SQLite emit nothing for it and need nothing: every collation
 * `initdb` creates is deterministic, and SQLite's default collating sequence is `BINARY`, so
 * both already compare exactly.
 *
 * **`saga_id`, `definition_id` and `status` are `VARCHAR` on every engine**, where the
 * installer used those widths on MySQL and unbounded `TEXT` on SQLite and PostgreSQL. MySQL
 * cannot key a TEXT column without a prefix length, so 255 is the width the key always had
 * to be; what changes is that PostgreSQL now enforces the same bound. SQLite does not
 * enforce it at all — a `VARCHAR(n)` column there has TEXT affinity and the length is not
 * checked — so its shape is unchanged in practice. Net effect: an id longer than 255
 * characters, or a status longer than 32, is rejected on PostgreSQL where it used to be
 * stored. `SagaStatus`'s longest case is `compensating`, at twelve characters, leaving room
 * for the additive cases an `#[Api]` enum may gain.
 *
 * **The timestamp columns change spelling but not behaviour.** MySQL gets `DATETIME(6)`
 * where the installer wrote bare `DATETIME`, meaning `DATETIME(0)`: `save()` writes
 * `$state->startedAt->format('Y-m-d H:i:s')`, so the six fractional digits are always zero,
 * and the driver returns `.000000` on the end of a string that `hydrate()` parses through
 * `new DateTimeImmutable(...)` to the same instant. Nothing in the storage compares those
 * strings. SQLite gets `DATETIME` where the installer wrote `TEXT`; SQLite derives affinity
 * from the declared type name, and `DATETIME` matches none of the INT, CHAR, CLOB, TEXT,
 * BLOB, REAL, FLOA or DOUB rules, so the column has NUMERIC rather than TEXT affinity.
 * NUMERIC affinity only converts a value whose entire text is a well-formed integer or real
 * literal, and a `'Y-m-d H:i:s'` string is not one, so every value this storage writes is
 * stored as text exactly as before and sorts and compares the same way. PostgreSQL keeps
 * `TIMESTAMP`, which is what this storage needs and the opposite of what 20260327000001 does
 * for the second-factor tables: the values are naive wall-clock strings carrying no offset,
 * and under `TIMESTAMPTZ` PostgreSQL would resolve them against the session `TimeZone` on
 * write and hand back an offset on read, shifting the instant by an amount that depends on
 * server configuration.
 *
 * **`saga_id` gains `NOT NULL` on SQLite.** The compiler declares every non-nullable column
 * `NOT NULL` and adds `PRIMARY KEY (saga_id)` as a table constraint; the installer wrote
 * `saga_id TEXT PRIMARY KEY`, and a `PRIMARY KEY` column in a SQLite rowid table is nullable
 * — a legacy behaviour SQLite preserves on purpose. So a fresh SQLite install now gets what
 * MySQL declared and PostgreSQL implied all along. `save()` binds `SagaState::$sagaId`, a
 * non-nullable `string`, so nothing the framework writes could have been NULL either way.
 *
 * `definition_version` and `current_step_index` are `INTEGER` on all three engines, where
 * the installer wrote `INT` on MySQL. They are the same type: `INTEGER` is a MySQL synonym
 * for `INT`.
 *
 * Three differences remain that are spelling alone, listed so that a mechanical diff of the
 * two statements turns up nothing this docblock has not accounted for. The key is a table
 * constraint, `PRIMARY KEY (saga_id)`, where the installer wrote `PRIMARY KEY` inline on the
 * column — the same constraint, and the compiler's choice rather than this file's.
 * `completed_at` loses the installer's explicit `NULL` keyword on MySQL, which every engine
 * treats as the default for a column that does not say `NOT NULL`. And the statement is a
 * bare `CREATE TABLE` where the installer wrote `CREATE TABLE IF NOT EXISTS`; that one is
 * not cosmetic, and what stands in its place is the subject of the Idempotency section
 * below.
 *
 * ## The primary key is the upsert's contract
 *
 * All three arms of `DatabaseSagaStateStorage::upsertSql()` depend on a unique key over
 * exactly `(saga_id)`: SQLite's `INSERT OR REPLACE`, MySQL's `ON DUPLICATE KEY UPDATE` and
 * PostgreSQL's `ON CONFLICT (saga_id) DO UPDATE`. PostgreSQL is the strictest of the three —
 * with no unique index or constraint whose columns are precisely `(saga_id)` it raises
 * SQLSTATE 42P10 at the first `save()`, not at migration time. Widening the key, dropping
 * it, or expressing it as a UNIQUE index over a different column set would therefore pass
 * every migration test and fail in production.
 *
 * There are no secondary indexes, because the installer emits none on any engine. Adding one
 * here would exist on fresh installs alone — `up()` does not touch a table that is already
 * there — so the fleet would split on indexes too. That is also why this file does not use
 * {@see \Pulsar\Database\Schema\IndexOperations}: it has no index to ensure. Should one ever
 * be added, it goes through that helper and its name is prefixed with the table
 * (`idx_saga_states_*`), because PostgreSQL and SQLite scope index names per schema and per
 * database respectively while MySQL scopes them per table, and because MySQL rejects
 * `CREATE INDEX IF NOT EXISTS` as a syntax error rather than tolerating it.
 *
 * ## Idempotency
 *
 * {@see DdlCompiler} emits a bare `CREATE TABLE`, not `CREATE TABLE IF NOT EXISTS`, so
 * re-runnability is not free here: `up()` asks {@see TableIntrospector} whether the table is
 * there and returns if it is. That guard is this statement's own postcondition rather than a
 * flag several steps share — the definition declares no non-unique index, so
 * `compileCreate()` returns exactly one statement, and "the table exists" is precisely "that
 * statement has run". The distinction is the defect 20260805000001 documents: a shared guard
 * lets a resumed run skip work and still be recorded as applied. It applies the moment a
 * second statement is added here, which must then carry a check of its own.
 *
 * That a resumed run happens at all is why any of this matters, and it happens on all three
 * engines rather than on the two an earlier draft of this file named. `MigrationRunner`
 * wraps `up()` in a transaction and then writes the applied record in a separate statement,
 * after that transaction has already committed. A process that dies in the window between
 * the two leaves a table no ledger row mentions, and the next run replays this file from the
 * top. No engine closes that window, because the record was never inside the transaction.
 *
 * The earlier draft reasoned differently and got the engines wrong, so the correction is
 * recorded rather than quietly applied: it said MySQL and SQLite each "commit each DDL
 * statement as it runs", citing {@see SchemaCapabilities::supportsTransactionalDdl()}. Half
 * of that was true. MySQL, measured on 8.0.46, commits implicitly before and after each of
 * the statements a migration issues — `CREATE TABLE`, `ALTER TABLE`, `CREATE INDEX`, `DROP
 * TABLE` — so the runner's transaction is no safety net there at all, and PDO's own
 * `inTransaction()` reads false the moment the DDL lands. SQLite does not behave that way:
 * its DDL is transactional, measured on 3.53.2 through PDO rather than assumed. A
 * `CREATE TABLE` inside a transaction is undone by `ROLLBACK`; an `INSERT` issued before a
 * `CREATE TABLE` in the same transaction is rolled back with it instead of being committed
 * by the DDL; and a statement that fails leaves the transaction open and is withdrawn along
 * with the successful ones before it.
 *
 * The capability was the other half of the mistake and has been corrected, so it can be
 * cited now rather than worked around: it answers true for SQLite, and
 * {@see \Pulsar\Tests\Contract\TransactionalDdlContractTest} settles the question against
 * a live server on each engine instead of against a value written beside the assertion.
 * Nothing on this path reads it either way — `MigrationRunner` wraps `up()` without asking —
 * so the guard below stands regardless; what makes it necessary is the unrecorded-commit
 * window above, which no engine's DDL semantics can close.
 *
 * Two runners cannot race between the check and the `CREATE`: `MigrationRunner` holds an
 * advisory lock on MySQL and PostgreSQL, and an exclusive flock on SQLite, for the length of
 * a run.
 *
 * ## `down()` does not destroy data
 *
 * On a host that had already run `installSchema()` the table pre-existed, `up()` changed
 * nothing, and dropping the table would be destruction rather than reversal. The rows are
 * in-flight sagas: once they are gone those sagas cannot be resumed and any compensation
 * they still owed will never run.
 *
 * So `down()` drops `saga_states` only when it is empty, and otherwise raises with the
 * table's name and the number of rows standing in the way. The case where a rollback is
 * meaningful — a fresh install, where `up()` really did create the table and nothing has run
 * since — reverses cleanly. An operator who wants the table gone along with its rows deletes
 * them first, which is a deliberate act rather than a side effect of a rollback.
 *
 * The drop goes through the compiler too, which spells it `DROP TABLE IF EXISTS`, so a
 * rollback that runs twice does not fail on the table's absence.
 *
 * ## Scope
 *
 * This file covers `saga_states` only. `SagaWiring` also binds
 * `DatabaseSagaStepResultStorage`, whose `saga_step_results` table has never had an
 * installer anywhere in the repository, so its DDL has no shipped shape to reproduce and has
 * to be designed from the queries that read it. That is a schema decision on its own and has
 * its own migration, rather than being smuggled into one whose whole purpose is to preserve
 * a shape that already exists in the field.
 */
return new class implements MigrationInterface {
    private const string TABLE = 'saga_states';

    public function up(ConnectionInterface $connection): void
    {
        // The compiler emits a bare CREATE TABLE, so this is what makes the step
        // re-runnable — and it is that one statement's own postcondition, not a flag a
        // later step could share. See the class docblock.
        if (new TableIntrospector($connection)->tableExists(self::TABLE)) {
            return;
        }

        foreach ($this->compiler($connection)->compileCreate($this->definition()) as $sql) {
            $connection->execute($sql);
        }
    }

    /**
     * Reverse `up()` where `up()` did something, and refuse where it did not.
     *
     * A host that already had this table from `installSchema()` saw `up()` do nothing, so
     * dropping the table here would destroy in-flight saga state this migration never
     * created. Emptiness is the test that separates the two cases, and it is asked of the
     * table rather than inferred from anything the runner recorded.
     *
     * @throws RuntimeException When the table holds rows, naming it and the count.
     */
    public function down(ConnectionInterface $connection): void
    {
        if (!new TableIntrospector($connection)->tableExists(self::TABLE)) {
            return;
        }

        $rows = $this->rowCount($connection);

        if ($rows > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to roll back: %s holds %d row(s) of saga state that this migration did '
                . 'not create — the table pre-existed on this host, so up() was a no-op and '
                . 'dropping it now would strand every unfinished saga with its compensation '
                . 'unrun. Delete the rows deliberately if the table really is meant to go.',
                self::TABLE,
                $rows,
            ));
        }

        foreach ($this->compiler($connection)->compileDropTable(self::TABLE) as $sql) {
            $connection->execute($sql);
        }
    }

    /**
     * One description of the table, for every engine.
     *
     * Column order, names, nullability and the key match what
     * `DatabaseSagaStateStorage::installSchema()` created; the engine-specific spelling is
     * {@see DdlCompiler}'s job, and the handful of places where its vocabulary cannot reach
     * the installed text are enumerated in the class docblock.
     *
     * No column carries a DEFAULT. Every non-nullable column is always bound by `save()`,
     * and giving one a default would change what an INSERT that omitted it does — turning a
     * loud failure into a silently invented value.
     *
     * `step_results` and `context` hold JSON and are still declared as text rather than as
     * {@see SchemaColumnType::Json}, for the reason they always were: the storage does its
     * own `json_encode(..., JSON_THROW_ON_ERROR)` on write and `json_decode()` on read, so a
     * native type buys nothing and changes the bytes at rest — PostgreSQL's JSONB reorders
     * object keys and strips whitespace, MySQL normalises, and MariaDB aliases JSON to
     * LONGTEXT plus a `CHECK (json_valid(...))` that would reject rows the installer had
     * accepted.
     */
    private function definition(): TableDefinition
    {
        return new TableDefinition(
            name: self::TABLE,
            columns: [
                // 255 rather than an unbounded text type because MySQL cannot key a
                // TEXT/BLOB column without a prefix length, which is the bound the installer
                // already imposed on that engine.
                new SchemaColumn(
                    name: 'saga_id',
                    type: SchemaColumnType::String,
                    primaryKey: true,
                    length: 255,
                ),
                new SchemaColumn(
                    name: 'definition_id',
                    type: SchemaColumnType::String,
                    length: 255,
                ),
                new SchemaColumn(name: 'definition_version', type: SchemaColumnType::Integer),
                new SchemaColumn(name: 'current_step_index', type: SchemaColumnType::Integer),
                // BigText, not Text, because the installer declared both this column and
                // `context` LONGTEXT on MySQL and Text compiles to MySQL's TEXT — 65,535
                // bytes against LONGTEXT's four gibibytes. `step_results` grows with the
                // number of completed steps and the size of each step's output, and
                // `context` is whatever the application put there, so the narrow ceiling is
                // reachable; past it `save()` fails with error 1406 under the shipped
                // `sql_mode` and truncates silently without it, and truncated JSON makes
                // `hydrate()` throw on the next resume. PostgreSQL and SQLite are unchanged:
                // their TEXT is already the widest text either engine has.
                new SchemaColumn(name: 'step_results', type: SchemaColumnType::BigText),
                // Comfortably holds every SagaStatus case; the longest, `compensating`, is
                // twelve characters.
                new SchemaColumn(
                    name: 'status',
                    type: SchemaColumnType::String,
                    length: 32,
                ),
                new SchemaColumn(name: 'context', type: SchemaColumnType::BigText),
                new SchemaColumn(name: 'started_at', type: SchemaColumnType::DateTime),
                // The only nullable column: a saga that is still running has no completion
                // instant, and `hydrate()` reads this one through `getNullableString()`.
                new SchemaColumn(
                    name: 'completed_at',
                    type: SchemaColumnType::DateTime,
                    nullable: true,
                ),
            ],
            // What the installer wrote as `COLLATE=utf8mb4_bin`, asked for as an intent
            // rather than as an engine's spelling. It is set once for the table instead of
            // per column because that is what the installer did and because MySQL columns
            // inherit the table default, so the two produce the same shape here; a per
            // column collation exists for a table that wants a mixed one, which this is
            // not. `saga_id`'s comparison rule is the one with consequences — see the class
            // docblock.
            collation: SchemaCollation::Exact,
        );
    }

    /**
     * The variant is passed, not defaulted.
     *
     * {@see SchemaCapabilities} answers several of its questions differently for MariaDB
     * than for MySQL, and only the server's own `VERSION()` string tells them apart. A
     * capabilities object built without it would describe a server that did not answer.
     */
    private function compiler(ConnectionInterface $connection): DdlCompiler
    {
        return new DdlCompiler(
            $connection->driver(),
            new SchemaCapabilities($connection->driver(), $connection, $connection->variant()),
        );
    }

    /**
     * `COUNT(*)` with a lower-case alias: every supported engine returns the alias exactly
     * as written here, so the read needs no dialect of its own.
     */
    private function rowCount(ConnectionInterface $connection): int
    {
        foreach ($connection->query('SELECT COUNT(*) AS c FROM ' . self::TABLE)->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }
};
