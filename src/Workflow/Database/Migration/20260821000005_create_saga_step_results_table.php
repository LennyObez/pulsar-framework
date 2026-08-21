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
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Database\Schema\TableIntrospector;

/**
 * The table saga step execution is recorded in — which has never existed anywhere.
 *
 * `saga_step_results` is the one table in this batch with no shipped DDL to reproduce.
 * The other four replace an `installSchema()` method on their storage; this one replaces
 * nothing, because {@see \Pulsar\Workflow\Internal\Storage\DatabaseSagaStepResultStorage}
 * never had an installer, no migration created the table, and no deploy script in the
 * repository does either. `SagaWiring` nevertheless binds that storage as the
 * `SagaStepResultStorageInterface` port whenever a connection manager is present and
 * nothing has already bound `SagaStateStorageInterface`, and `SagaOrchestrator` calls
 * `record()` on whatever it receives for that port at the start of every forward step and
 * every compensating one. So a saga run with the port resolved from the container fails
 * at its first step on a missing table, and has since the module shipped. The storage's
 * unit test never caught it: it stubs `ConnectionInterface` outright, so it asserts on the
 * SQL string handed to a stub and issues no DDL against any engine.
 *
 * ## How the shape below was derived
 *
 * From the DML, not from a design. Every column here is one the storage names in a
 * statement, and nothing else is here:
 *
 *   - `record()` INSERTs twelve columns by name: `id`, `instance_id`, `step_name`,
 *     `step_index`, `direction`, `status`, `idempotency_key`, `attempts`, `result_data`,
 *     `error_message`, `started_at`, `completed_at`.
 *   - `updateStatus()` and `markCompleted()` write `status`, `error_message`,
 *     `result_data` and `completed_at` `WHERE id = :id`; `incrementAttempts()` issues
 *     `attempts = attempts + 1 WHERE id = :id`. Four statements keyed on `id` alone is
 *     what makes `id` the primary key.
 *   - `hydrateResult()` fixes the nullability. It reads `id`, `instance_id`, `step_name`,
 *     `step_index`, `direction`, `status`, `attempts` and `started_at` through
 *     `Row::getString()`/`getInt()`, which throw on NULL, so those eight are `NOT NULL`;
 *     it reads `idempotency_key`, `result_data`, `error_message` and `completed_at`
 *     through `getNullableString()`, so those four are nullable. `record()` agrees —
 *     those four are the only bindings that can be null.
 *   - `getByInstance()`, `getByDirection()` and `findByIdempotencyKey()` supply the
 *     access paths the indexes below serve.
 *
 * The derivation was then checked against ADR-0027, which specifies this table. Its
 * column list matches the one above exactly — same twelve names, same four nullable. A
 * reader verifying this file should start there: read the storage's four write statements
 * and three read statements, then the table in ADR-0027 under "Saga step results table",
 * and confirm the two agree with what is built in `definition()`.
 *
 * Four of the ADR's types are deliberately not taken, and each is a decision rather than
 * an oversight:
 *
 *   - **`id` and `instance_id` are `VARCHAR`, not `UUID`.** Both values come from
 *     `SagaOrchestrator::generateId()`, which returns `bin2hex($randomizer->getBytes(16))`
 *     — 32 lower-case hex characters and no hyphens. PostgreSQL's `uuid` type accepts
 *     that spelling on input but returns the canonical hyphenated form on read, so the id
 *     `hydrateResult()` hands back would not be the id `record()` wrote, on PostgreSQL
 *     and on no other engine. It also rejects ids that are not UUIDs at all, which the
 *     port permits: `SagaStepResult::$id` is a plain `string` supplied by the caller, and
 *     the storage's own unit tests use `'step-1'`.
 *
 *   - **No foreign key on `instance_id`.** ADR-0027 calls it an FK to
 *     `workflow_instances`; the value actually written is `SagaState::sagaId`, whose row
 *     lives in `saga_states`. A constraint against `workflow_instances` would reject
 *     every insert the orchestrator makes. One against `saga_states` is expressible but
 *     is declined on its merits: `SagaStateStorageInterface` and
 *     `SagaStepResultStorageInterface` are separately bindable ports, so the parent row
 *     is not guaranteed to be in this database at all; and on an append-only audit table
 *     the referential action has no good answer — `RESTRICT` blocks any cleanup of
 *     finished sagas, `CASCADE` destroys the record of which compensations ran along with
 *     the saga it belonged to. The decision has to be taken now either way, because
 *     SQLite cannot add a foreign key to an existing table
 *     ({@see SchemaCapabilities::supportsAddForeignKey()}), and this file takes it as
 *     "no".
 *
 *   - **`direction` and `status` are `VARCHAR(20)`, not `ENUM`.** The portable layer can
 *     express the closed set — {@see SchemaColumnType::Enum} compiles to a native `ENUM`
 *     on MySQL and to `VARCHAR ... CHECK (col IN (...))` elsewhere — and the cost of
 *     taking it is paid later: widening the set means altering a CHECK constraint, which
 *     on SQLite means rebuilding the table, and this migration will already have run
 *     everywhere by then. `SagaStepStatus` has five cases today and is the kind of enum
 *     that gains one. The set is enforced in PHP at both ends instead: writes bind
 *     `$result->status->value`, and `hydrateResult()` reads through
 *     `SagaStepStatus::from()`, which raises on a value outside it. What that does not
 *     buy is rejection at the engine of a value written by something other than this
 *     storage — a direct `UPDATE` by an operator can still put an unknown status in the
 *     column, and it will fail on the next read rather than on the write.
 *
 *   - **`result_data` is text, not `JSON`/`JSONB`.** The storage does its own
 *     `json_encode(..., JSON_THROW_ON_ERROR)` on write and `json_decode()` on read, so a
 *     native type buys nothing and changes the bytes at rest. PostgreSQL's `jsonb`
 *     reorders object keys, strips insignificant whitespace, and rejects outright the
 *     NUL escape inside a string that `json_encode()` emits for a step output carrying a
 *     NUL byte — legal JSON, which would insert on MySQL and SQLite and fail on
 *     PostgreSQL alone. MariaDB aliases `JSON` to `LONGTEXT` and attaches a
 *     `CHECK (json_valid(...))` the other engines do not have. Text stores what was
 *     written, on all three.
 *
 * ## Text width and text comparison, both of which MySQL defaults wrongly for this table
 *
 * These two are settled here rather than inherited, and neither is copied from anywhere:
 * unlike the four tables migrated alongside it, `saga_step_results` has no installer to
 * take a shape from. There is no `installSchema()` on
 * {@see \Pulsar\Workflow\Internal\Storage\DatabaseSagaStepResultStorage} — at any revision
 * — so both choices are argued from what the storage does with the columns, and checked
 * against what the sibling installers chose for the same kind of content.
 *
 *   - **`result_data` and `error_message` are {@see SchemaColumnType::BigText}**, which
 *     compiles to `LONGTEXT` on MySQL and to `TEXT` on PostgreSQL and SQLite, where that
 *     is already the widest either offers. The narrow {@see SchemaColumnType::Text} would
 *     compile to MySQL's `TEXT` and cap both columns at 65,535 *bytes*. Neither column is
 *     sized by a form: `result_data` is `json_encode()` over whatever a step returned, and
 *     the sibling installer for `saga_states` declared the two columns holding the same
 *     kind of value — `step_results` and `context` — as `LONGTEXT` for exactly this
 *     reason. Over the cap MySQL raises error 1406 under the `sql_mode` it ships with, and
 *     truncates silently without it; `SagaOrchestrator::runForward()` calls
 *     `recordStepCompleted()` outside its `try`, so that failure escapes `execute()` after
 *     the step's side effects have already happened. `error_message` is the worse of the
 *     two despite holding only `$e->getMessage()`: an exception message is unbounded — a
 *     driver error quotes the statement it rejected, a validation failure dumps what it
 *     rejected — and it is written from inside a `catch`, so a write that fails there
 *     replaces the record of why the saga failed with a report about a column width.
 *
 *   - **The table is created `COLLATE=utf8mb4_bin` on MySQL**, through
 *     {@see SchemaCollation::Exact} on the {@see TableDefinition}. Without it the table
 *     takes the database default — `utf8mb4_0900_ai_ci` on MySQL 8,
 *     `utf8mb4_general_ci` on MariaDB, both case- and accent-insensitive — and `id` and
 *     `idempotency_key` are compared for equality by four of the storage's seven
 *     statements. Under an insensitive collation two values differing only in case are
 *     one key: `INSERT` collides on the primary key, and `updateStatus()` reaches a row
 *     it did not mean. Nothing reachable through `SagaOrchestrator` can hit it —
 *     `bin2hex()` emits lower-case hex only — but `SagaStepResult::$id` is a plain
 *     `string` supplied by the caller, and a deployment issuing mixed-case ids of its own
 *     is exposed on MySQL alone. It is set at the table rather than per column because
 *     every character column here holds an identifier or an enum case, so there is no
 *     column that wants the other answer, and because that is the spelling the four
 *     sibling installers used. PostgreSQL and SQLite emit no clause and need none: every
 *     collation `initdb` creates is deterministic and SQLite's default collating sequence
 *     is `BINARY`, so both already compare byte-exact.
 *
 * ## The one thing the compiled shape says that a hand-written DDL would not
 *
 * `started_at` and `completed_at` become `DATETIME(6)` on MySQL, because that is what
 * {@see DdlCompiler} maps a datetime column to; a DDL written by hand for this table would
 * have said bare `DATETIME`, meaning `DATETIME(0)`. The divergence is inert. The storage
 * writes `'Y-m-d H:i:s'`, so the six fractional digits are always zero, and the value reads
 * back with a `.000000` suffix that nothing wrote — which `hydrateResult()` parses through
 * `new DateTimeImmutable(...)` to the same instant, and which changes neither ordering nor
 * range comparison. It is recorded because the precision is the compiler's rather than a
 * decision this file took, and a reader comparing the created table against the column list
 * below should not be left wondering which of the two it was.
 *
 * On PostgreSQL those two columns are `TIMESTAMP` — without time zone, which is the type
 * this storage needs. `record()` writes a naive local wall-clock string carrying no
 * offset and `hydrateResult()` reads it straight back; under `TIMESTAMPTZ` PostgreSQL
 * would resolve that string against the session `TimeZone` on write and return an offset
 * on read, shifting the instant by an amount that depends on server configuration.
 *
 * ## The indexes, and why the idempotency one is not unique
 *
 * Both come from the `SELECT`s and nothing else. `idx_saga_step_results_instance` covers
 * `(instance_id, step_index, started_at)`, which is `getByInstance()`'s `WHERE` and its
 * `ORDER BY step_index ASC, started_at ASC` in one; `getByDirection()` uses the same
 * index for its `instance_id` predicate and filters on `direction` as a residual, which
 * is why there is no second index carrying `direction` — putting it between
 * `instance_id` and `step_index` would satisfy that one query's predicate and stop the
 * other's ordering being served at all.
 *
 * `idx_saga_step_results_idempotency` covers `findByIdempotencyKey()`. It is
 * deliberately **not** unique. `SagaOrchestrator::recordStepStarted()` inserts a new row
 * with a new `id` unconditionally and consults `findByIdempotencyKey()` nowhere, so a
 * saga resumed over a step whose `forwardIdempotencyKey` closure is a pure function of
 * the context — the intended way to write one — recomputes the same key and inserts a
 * second row with it. A unique index would turn that resume into a constraint violation
 * at the first step, which is the opposite of what durable execution is for. The storage
 * is built for the duplicate: `findByIdempotencyKey()` takes `$result->first()` rather
 * than asserting a single row. Should the orchestrator ever gate `record()` on that
 * lookup, uniqueness becomes expressible and wants its own migration.
 *
 * The index is narrowed to `WHERE idempotency_key IS NOT NULL` where the engine has
 * partial indexes, which excludes most of the table: a step with no idempotency closure
 * binds null, and `recordStepSkipped()` always does. MySQL has no such syntax, so
 * {@see IndexOperations::ensure()} drops the predicate there and the index covers every
 * row — wider than asked for, never a wrong answer, and the lookup never matches a null
 * anyway.
 *
 * Index names are prefixed with the table because PostgreSQL scopes them per schema and
 * SQLite per database, where MySQL scopes them per table; and they are created through
 * {@see IndexOperations} rather than declared on the `TableDefinition` because
 * {@see DdlCompiler::compileCreate()} prefixes index names a second time on PostgreSQL,
 * which would leave the created name and the name every later existence check asks about
 * disagreeing on that engine alone.
 *
 * ## Idempotency, and the two ways a run comes back
 *
 * `MigrationRunner::executeMigrationSafely()` wraps `up()` in a transaction, and
 * `runPending()` calls `recordMigration()` only after `up()` has returned — outside that
 * transaction. So a run that does not reach the ledger row is attempted again from the top.
 * There are two ways to be in that position, and conflating them is how an earlier revision
 * of this docblock reached a conclusion that is simply false.
 *
 * **A death inside `up()`.** What the retry finds depends entirely on the engine:
 *
 *   - **MySQL discards the wrapper.** `CREATE TABLE` and `CREATE INDEX` each force an
 *     implicit commit, so the enclosing transaction ends at the first statement and every
 *     statement after it stands on its own. A run interrupted after the `CREATE` leaves a
 *     committed, unrecorded, index-less table, and the retry meets it.
 *   - **PostgreSQL and SQLite keep it.** Both roll DDL back with everything else, so the
 *     retry meets the schema exactly as it was before the run.
 *
 * **A death after `up()` returned and before `recordMigration()` committed.** No engine
 * closes this one, because the record was never inside the transaction. `up()` succeeded,
 * the transaction committed, the table and both indexes exist — and the version is
 * unrecorded, so `getPending()` offers this file again on the next run. PostgreSQL and
 * SQLite reach it exactly as MySQL does.
 *
 * That second door is why the sentence this paragraph replaced was wrong. It said the guards
 * "can never fire" on PostgreSQL and SQLite. They fire there whenever a deploy is killed in
 * that window — an OOM, a timeout, a drained node — and if they were removed on the strength
 * of that claim, the retry would meet a `CREATE TABLE` against a table that already exists
 * and the migration would be permanently unable to complete on the two engines it was
 * supposed to be free on.
 *
 * The SQLite half of the first door is worth stating precisely, because it is also easy to
 * get wrong and the same earlier revision did: **SQLite does support transactional DDL**. A
 * `CREATE TABLE` and a `CREATE INDEX` issued inside a `BEGIN` both disappear on `ROLLBACK`,
 * the transaction stays open across them, and objects created before it survive — measured
 * against SQLite 3.53.2 through PDO rather than reasoned about.
 * {@see SchemaCapabilities::supportsTransactionalDdl()} answered `false` for SQLite when this
 * file was written, which is why the measurement was made instead of the method being cited;
 * it has since been corrected, and
 * {@see \Pulsar\Tests\Contract\TransactionalDdlContractTest} keeps it honest by performing
 * the experiment against every configured engine.
 *
 * The guards therefore stay, on all three engines, and each of the three steps below is gated
 * by its own postcondition rather than by a flag the others share: "does the table exist"
 * gates the `CREATE`, and each index gates on its own presence inside
 * {@see IndexOperations::ensure()}. A run interrupted after the `CREATE` resumes into the
 * index work rather than skipping it, which is the failure 20260805000001 documents at
 * length. What differs by engine is not whether the guards can fire but which door the retry
 * came through — half-built on MySQL, fully built and unrecorded anywhere.
 *
 * Existence is decided by name, so an index that already exists under one of these names
 * with different columns is left as it is. On this table that cannot happen: the only
 * statement that creates it is the one below, in the same `up()` that creates the table.
 *
 * ## `down()` refuses to destroy the audit trail
 *
 * A rollback that drops a populated `saga_step_results` is not a reversal. The rows are
 * the only record of which compensations have run: `SagaState` carries forward step
 * results, and which irreversible steps were skipped and which compensating actions
 * completed exists here and nowhere else. Losing them does not fail loudly — the next
 * operator to reconstruct what a failed saga did simply finds nothing.
 *
 * So `down()` drops the table only when it is empty, and otherwise raises with the table
 * name and the row count. A fresh install — the only case where rolling this migration
 * back means anything — reverses cleanly. The `COUNT(*)` is a full scan on PostgreSQL and
 * InnoDB; that is the correct price for a statement whose alternative is silent data
 * loss, and it is paid once, in a rollback.
 */
return new class implements MigrationInterface {
    private const string TABLE = 'saga_step_results';
    private const string INSTANCE_INDEX = 'idx_saga_step_results_instance';
    private const string IDEMPOTENCY_INDEX = 'idx_saga_step_results_idempotency';

    /**
     * Widths, and what a value that outgrows one does.
     *
     * `id` holds `bin2hex(16 bytes)` — 32 characters — and is kept narrow because it is
     * the clustered primary key on InnoDB, so every secondary index entry carries a copy
     * of it. 64 leaves room for a UUID or ULID spelling from a caller supplying its own
     * ids. `instance_id` is 255 to match `saga_states.saga_id`, which
     * `DatabaseSagaStateStorage` declares as `VARCHAR(255)` on MySQL: a narrower column
     * here could not hold every id that table can. Both are the columns to widen — and
     * the caveat to know — if an application supplies longer ids of its own, since MySQL
     * outside strict mode truncates rather than refusing, and two ids truncated to the
     * same prefix are one key.
     */
    private const int ID_LENGTH = 64;
    private const int NAME_LENGTH = 255;

    /**
     * Wide enough for every case of both enums with room to spare: `compensating` is 12
     * characters and `completed` is 9. The width is not the constraint — see the class
     * docblock on why the closed set is enforced in PHP rather than by the engine — so it
     * is chosen generously rather than exactly. A value that outgrew it would be truncated
     * by a non-strict MySQL and then fail `SagaStepDirection::from()` on the way back out,
     * reporting the wrong problem at the far end of the round trip.
     */
    private const int ENUM_LENGTH = 20;

    public function up(ConnectionInterface $connection): void
    {
        if (!new TableIntrospector($connection)->tableExists(self::TABLE)) {
            $this->schemaManager($connection)->createTable($this->definition());
        }

        $indexes = new IndexOperations($connection);

        // getByInstance(): WHERE instance_id = ? ORDER BY step_index ASC, started_at ASC.
        // getByDirection() shares it and filters on direction as a residual predicate.
        $indexes->ensure(
            self::TABLE,
            self::INSTANCE_INDEX,
            ['instance_id', 'step_index', 'started_at'],
        );

        // findByIdempotencyKey(): WHERE idempotency_key = ?. Not unique — a resumed saga
        // recomputes the same key and inserts a second row with it. See the class docblock.
        $indexes->ensure(
            self::TABLE,
            self::IDEMPOTENCY_INDEX,
            ['idempotency_key'],
            where: 'idempotency_key IS NOT NULL',
        );
    }

    /**
     * Reverse `up()` on a database where it has nothing to undo, and refuse otherwise.
     *
     * The absence check first: a rollback that runs twice, or one following an `up()`
     * that never reached the `CREATE` — or that reached it on PostgreSQL or SQLite and
     * then failed, taking the `CREATE` back with it — meets no table and has nothing to
     * reverse. That is success, not an error, and it is asked as its own question rather
     * than folded into the count, which cannot be taken from a table that is not there.
     */
    public function down(ConnectionInterface $connection): void
    {
        if (!new TableIntrospector($connection)->tableExists(self::TABLE)) {
            return;
        }

        $rows = $this->rowCount($connection);

        if ($rows > 0) {
            throw new RuntimeException(sprintf(
                '%s holds %d row(s) and will not be dropped: they are the record of which '
                . 'saga steps ran and which compensations completed, and dropping the table '
                . 'destroys that rather than reversing this migration. Archive or delete the '
                . 'rows deliberately, then roll back again.',
                self::TABLE,
                $rows,
            ));
        }

        $this->schemaManager($connection)->dropTable(self::TABLE);
    }

    /**
     * The one definition of this table, compiled per engine by the layer that knows them.
     *
     * Built as a {@see TableDefinition} directly rather than through
     * {@see \Pulsar\Database\Schema\Blueprint}, for one reason: `Blueprint` reaches
     * `primaryKey: true` only through `id()`, which hard-codes an auto-incrementing
     * `BIGINT`. A string primary key has no fluent spelling there, and the alternative —
     * declaring `id` as `NOT NULL UNIQUE` and calling that equivalent — is not the same
     * table. InnoDB would cluster on a hidden row id instead of on `id`, so each of the
     * three statements that update a row by id becomes a secondary-index probe followed by
     * a clustered lookup rather than one seek; and `TableIntrospector::hasPrimaryKey()`
     * would answer "no" for a table that has one in every sense a later migration cares
     * about. The compiler emits a table-level `PRIMARY KEY (id)` from the definition below
     * on every engine.
     *
     * Indexes are deliberately absent from the definition; they are created afterwards
     * through {@see IndexOperations} for the naming reason given in the class docblock.
     */
    private function definition(): TableDefinition
    {
        return new TableDefinition(
            name: self::TABLE,
            columns: [
                new SchemaColumn(
                    name: 'id',
                    type: SchemaColumnType::String,
                    primaryKey: true,
                    length: self::ID_LENGTH,
                ),
                new SchemaColumn(
                    name: 'instance_id',
                    type: SchemaColumnType::String,
                    length: self::NAME_LENGTH,
                ),
                new SchemaColumn(
                    name: 'step_name',
                    type: SchemaColumnType::String,
                    length: self::NAME_LENGTH,
                ),
                new SchemaColumn(
                    name: 'step_index',
                    type: SchemaColumnType::Integer,
                ),
                new SchemaColumn(
                    name: 'direction',
                    type: SchemaColumnType::String,
                    length: self::ENUM_LENGTH,
                ),
                new SchemaColumn(
                    name: 'status',
                    type: SchemaColumnType::String,
                    length: self::ENUM_LENGTH,
                ),
                new SchemaColumn(
                    name: 'idempotency_key',
                    type: SchemaColumnType::String,
                    nullable: true,
                    length: self::NAME_LENGTH,
                ),
                // `incrementAttempts()` issues `attempts = attempts + 1`, which yields
                // NULL from NULL on every engine and would silently erase the counter.
                // NOT NULL is what makes that statement total; the default covers a writer
                // that omits the column, which `record()` never does.
                new SchemaColumn(
                    name: 'attempts',
                    type: SchemaColumnType::Integer,
                    default: 0,
                    hasDefault: true,
                ),
                // Both are sized by data rather than by a form, so both take the wide
                // text type: LONGTEXT on MySQL, TEXT on the two engines where TEXT is
                // already unbounded. See the class docblock on what the narrow type
                // costs, and why `error_message` is the worse of the two to lose.
                new SchemaColumn(
                    name: 'result_data',
                    type: SchemaColumnType::BigText,
                    nullable: true,
                ),
                new SchemaColumn(
                    name: 'error_message',
                    type: SchemaColumnType::BigText,
                    nullable: true,
                ),
                new SchemaColumn(
                    name: 'started_at',
                    type: SchemaColumnType::DateTime,
                ),
                new SchemaColumn(
                    name: 'completed_at',
                    type: SchemaColumnType::DateTime,
                    nullable: true,
                ),
            ],
            // Every character column here holds an identifier or an enum case, and four
            // of the storage's seven statements compare one for equality, so the whole
            // table wants byte-exact comparison and no column wants the other answer.
            // Emitted as `COLLATE=utf8mb4_bin` on MySQL, where the server default folds
            // case; PostgreSQL and SQLite already compare this way and take no clause.
            collation: SchemaCollation::Exact,
        );
    }

    /**
     * The capabilities are read from the live connection, variant included, so a MariaDB
     * server answering through the MySQL driver is recognised as one rather than assumed
     * to be MySQL.
     */
    private function schemaManager(ConnectionInterface $connection): SchemaManager
    {
        $capabilities = new SchemaCapabilities(
            $connection->driver(),
            $connection,
            $connection->variant(),
        );

        return new SchemaManager(
            $connection,
            new DdlCompiler($connection->driver(), $capabilities),
            $capabilities,
        );
    }

    /**
     * `COUNT(*)` returns one row on every engine, and PostgreSQL returns its `bigint` as
     * a string through PDO — which is why the count is taken through `Row::getInt()`
     * rather than cast at the call site.
     */
    private function rowCount(ConnectionInterface $connection): int
    {
        $result = $connection->query('SELECT COUNT(*) AS c FROM ' . self::TABLE);

        foreach ($result->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }
};
