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
 * The two tables the workflow engine writes to, which no deployment has ever created.
 *
 * `workflow_instances` and `workflow_transitions` were defined only by
 * `DatabaseWorkflowStorage::installSchema()` and `DatabaseTransitionLog::installSchema()`.
 * Neither method had a caller — not in `src/`, not in `bin/`, not in any extension, not in a
 * test. `WorkflowWiring` binds a `DatabaseWorkflowStorage` and a `DatabaseTransitionLog` as
 * the defaults for `WorkflowStorageInterface` and `TransitionLogInterface`, so every
 * deployment that supplies no storage of its own runs on these two, and `bin/pulsar`
 * registers the timeout-sweep command against whatever `WorkflowStorageInterface` resolves
 * to. The engine has therefore always been wired up against tables nothing brings into
 * existence, and the first `WorkflowEngine::start()` on a fresh installation failed on a
 * missing relation.
 *
 * Both `installSchema()` methods are deleted in the same change that adds this file. This
 * migration is now the only definition of either table, which is the point: two definitions
 * of one schema drift, and the drift is invisible, because the runner tracks migrations by
 * path and the CRC32 in `MigrationRepository` buckets those paths rather than checksumming
 * their contents.
 *
 * ## Why the schema leaves the application process
 *
 * DDL at boot means the credentials the request-serving process holds must carry CREATE, and
 * in a regulated deployment that is a standing grant on a role with no legitimate
 * schema-changing work to do. It hands anything that reaches SQL execution through that role
 * the ability to add, reshape or drop a table — including `workflow_transitions`, which is an
 * append-only record of who moved which case to which state. It also makes the schema a
 * function of whichever build happens to boot first, applied without review, without a
 * version, without an ordering against other schema changes, and with no record that it
 * happened. A migration runs once, under deploy-time credentials that can be revoked
 * immediately afterwards, against a recorded version.
 *
 * ## One definition, compiled per engine
 *
 * The DDL below is a single {@see TableDefinition} per table, handed to {@see DdlCompiler} —
 * the layer whose job it is to know how each engine spells a type. Nothing here names an
 * engine. A file that writes `match ($driver)` has taken on knowing every engine the
 * framework will ever support, which is what makes adding one a breaking change rather than
 * an additive one; {@see \Pulsar\Tests\Unit\Integrity\DriverDispatchRatchetTest} exists to
 * stop that debt growing, and a migration is the last place to add to it, since the
 * framework's own compiler is already sitting right there.
 *
 * What that compiles to is worth naming, because it is not the DDL `installSchema()` emitted
 * in every particular. On all three engines, `workflow_instances` becomes:
 *
 *     id                 VARCHAR(255) NOT NULL      PRIMARY KEY (id)
 *     definition_id      VARCHAR(255) NOT NULL
 *     definition_version INTEGER      NOT NULL
 *     current_state      VARCHAR(255) NOT NULL
 *     context            <document>   NOT NULL
 *     version            INTEGER      NOT NULL
 *     status             VARCHAR(32)  NOT NULL
 *     started_at         <instant>    NOT NULL
 *     completed_at       <instant>    NULL
 *     started_by         VARCHAR(255) NOT NULL
 *     timeout_at         <instant>    NULL
 *
 * and `workflow_transitions`:
 *
 *     id                 VARCHAR(255) NOT NULL      PRIMARY KEY (id)
 *     instance_id        VARCHAR(255) NOT NULL
 *     from_state         VARCHAR(255) NOT NULL
 *     to_state           VARCHAR(255) NOT NULL
 *     transition_name    VARCHAR(255) NOT NULL
 *     actor              VARCHAR(255) NOT NULL
 *     reason             TEXT         NULL
 *     metadata           <document>   NOT NULL
 *     instance_version   INTEGER      NOT NULL
 *     created_at         <instant>    NOT NULL
 *
 * where `<instant>` is what {@see SchemaColumnType::DateTime} compiles to — `DATETIME` on
 * SQLite, `DATETIME(6)` on MySQL, `TIMESTAMP` on PostgreSQL — and `<document>` is what
 * {@see SchemaColumnType::BigText} compiles to: `LONGTEXT` on MySQL, which is the four-gibibyte
 * type the installer asked for there, and `TEXT` on SQLite and PostgreSQL, which is already
 * the widest either of those has and needs no second spelling. `reason` is deliberately not a
 * `<document>`; the installer left it narrow on MySQL and that line is drawn in the right
 * place, for the reason the `note()` helper gives.
 *
 * Both tables are declared {@see SchemaCollation::Exact}, which is `COLLATE=utf8mb4_bin` on
 * MySQL and nothing at all on SQLite and PostgreSQL, whose defaults already compare text byte
 * for byte. That is the installer's clause, asked for by intent rather than by name.
 *
 * No column carries a DEFAULT on any engine, exactly as before — every value is bound by the
 * INSERT, and adding CURRENT_TIMESTAMP or NOW() anywhere would be a behaviour change. No
 * column auto-increments: both primary keys are application-supplied strings written by
 * `WorkflowEngine` and `TransitionRecord`.
 *
 * Two further things a textual diff against the installer will report that are not differences
 * in shape, named here so nobody has to re-establish them. The integer columns are spelled
 * `INTEGER` where the installer wrote `INT`, and MySQL resolves both to one type — the column
 * `SHOW CREATE TABLE` prints back is `int` either way. And the nullable columns carry no `NULL`
 * keyword where the installer spelled one out; a column without `NOT NULL` is nullable on all
 * three engines, so that word was documentation and not a clause. The `NULL` in the shape above
 * is documentation in the same sense.
 *
 * ## The five places that shape differs from the installer's, and what each costs
 *
 *   1. **Identifier columns are VARCHAR on every engine, where SQLite and PostgreSQL had
 *      TEXT.** On SQLite a `VARCHAR` declaration carries TEXT affinity and no length is
 *      enforced at all, so the storage class and every comparison are unchanged. On
 *      PostgreSQL it is a real character bound where there was none — and it is the bound
 *      MySQL already imposed, so a value it now rejects is one no portable deployment could
 *      have stored anyway. `status` is bounded at 32 for the same reason; the longest
 *      `WorkflowInstanceStatus` value is `compensating`, at twelve characters.
 *
 *   2. **SQLite's `id` gains NOT NULL.** `id TEXT PRIMARY KEY` carries no NOT NULL, and
 *      SQLite permits NULL in any non-INTEGER primary-key column while MySQL and PostgreSQL
 *      imply it — the three dialects genuinely disagreed. The compiler emits the column and
 *      the key as separate clauses, so all three now agree. Nothing writes a null id.
 *
 *   3. **Timestamps are typed on SQLite and carry microsecond precision on MySQL.** The
 *      installer wrote SQLite `TEXT`; `DATETIME` there has NUMERIC affinity, and NUMERIC
 *      affinity converts a text value only when it is a well-formed integer or real literal.
 *      `Y-m-d H:i:s` is neither, so the value is stored as TEXT exactly as before — measured:
 *      `typeof(started_at)` answers `text`, `findExpiredTimeouts()`'s `timeout_at <= :now`
 *      range still selects the same rows, and `ORDER BY timeout_at` still sorts
 *      lexicographically the way it sorts chronologically. On MySQL, `DATETIME(6)` accepts
 *      the same writes as `DATETIME`; both storages write `->format('Y-m-d H:i:s')`, so the
 *      fractional part is always zero, MySQL returns it as `.000000`, and
 *      `new DateTimeImmutable($string)` parses that to the same instant. Neither storage
 *      compares a timestamp for equality — the sweep is a range and the rest are ORDER BY —
 *      so the wider type changes no result.
 *
 *      PostgreSQL keeps `TIMESTAMP` and not `TIMESTAMPTZ`, which is what
 *      {@see SchemaColumnType::DateTime} compiles to and is the correct half of that pair
 *      here. Both storages write a naive wall-clock string with no offset and read it back
 *      through `new DateTimeImmutable($string)`, which applies the PHP default timezone.
 *      TIMESTAMPTZ would have PostgreSQL interpret those strings in the session `TimeZone`
 *      and hand back offset-bearing values, shifting `started_at`, `completed_at`,
 *      `timeout_at` and `created_at` by the server offset.
 *
 *   4. **No `ENGINE=InnoDB`, and no `DEFAULT CHARSET=utf8mb4`.** The installer ended
 *      `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin`. The third clause is the
 *      one with teeth and it is emitted — every lookup in both storages is an equality on a
 *      character column (`WHERE id = :id`, `definition_id`, `status`, `instance_id`, and the
 *      optimistic-lock compare-and-set `WHERE id = :id AND version = :version`), and under a
 *      `_ci` server default `findById()` would hand back a row whose id differs only in case
 *      and `findByDefinition('café')` would match `cafe`. Asking for that by name is what
 *      {@see SchemaCollation::Exact} is, so the collation is not a divergence. The two clauses
 *      beside it are, and this is what each of them is worth.
 *
 *      The character set is a clause and not a behaviour here: MySQL derives a table's
 *      character set from its collation when only the collation is given, and `utf8mb4_bin`
 *      belongs to utf8mb4 and to nothing else, so the table lands on utf8mb4 by the same
 *      statement that asked for binary comparison. A separate `DEFAULT CHARSET` could only
 *      agree with it or contradict it.
 *
 *      The storage engine is genuinely dropped. `default_storage_engine` has been InnoDB
 *      since MySQL 5.5 and since MariaDB 10.2, and a server configured otherwise would be
 *      building non-transactional tables for the whole application rather than for these
 *      two — which a per-table clause cannot rescue and a connection preflight can. Neither
 *      PostgreSQL nor SQLite has the concept at all, so `src/Database/Schema` offers no
 *      vocabulary for it, and inventing one here would name an engine.
 *
 *      One consequence is worth stating because it is easy to attribute to the wrong clause:
 *      `idx_workflow_transitions_instance` leads with `instance_id VARCHAR(255)`, which is
 *      1,020 bytes in utf8mb4. InnoDB allows 3,072 bytes per indexed column under the DYNAMIC
 *      row format and 767 under COMPACT, so this fits on any server whose
 *      `innodb_default_row_format` is DYNAMIC — the default since 5.7.9. That is a property
 *      of that variable or of an explicit `ROW_FORMAT` clause, and never of `ENGINE=InnoDB`,
 *      which selects a storage engine and no row format at all. Dropping the clause therefore
 *      costs nothing here.
 *
 *   5. **The indexes now exist on all three engines.** They are the one place this migration
 *      adds something, and they are covered below. On MySQL they are also the one place the
 *      *statement* differs without the *result* differing: the installer declared both inline
 *      in `CREATE TABLE`, this migration issues a separate `CREATE INDEX` under the same name
 *      over the same columns, and MySQL builds the same secondary index either way.
 *
 * ## The indexes
 *
 * `idx_workflow_instances_timeout` and `idx_workflow_transitions_instance` were declared
 * inline in the MySQL `CREATE TABLE` and nowhere else, so on SQLite and PostgreSQL
 * `findExpiredTimeouts()`, `getHistory()` and `reconstructState()` full-scanned — the last two
 * over a table that only ever grows. Both are created here on every engine through
 * {@see IndexOperations}, under exactly the names MySQL already used. {@see
 * IndexOperations::ensure()} decides existence by name, and that is safe here because the
 * name and the columns are together the pair the installer declared: an index found under
 * that name on a host that ran `installSchema()` is the index this step would have built.
 * Any other name would silently produce a second, redundant index on MySQL alone.
 *
 * They are deliberately not declared on the {@see TableDefinition}. `DdlCompiler` prefixes a
 * non-unique index name with the table name on PostgreSQL when it does not already start with
 * it, which would leave PostgreSQL holding
 * `workflow_instances_idx_workflow_instances_timeout` while MySQL and SQLite kept the short
 * name — three engines, two names, and an `ensure()` in a later migration unable to find one
 * of them.
 *
 * Index DDL is never hand-written either. MySQL rejects `CREATE INDEX IF NOT EXISTS` and
 * `DROP INDEX IF EXISTS` as syntax errors and names the owning table on the drop that the
 * other engines do not — the exact defect that stopped 2FA from ever installing on MySQL.
 *
 * ## Idempotency, and why no step returns early
 *
 * `MigrationRunner::executeMigrationSafely()` wraps `up()` in a transaction, and what that
 * wrapper is worth differs by engine. MySQL gives each of the statements below an implicit
 * commit — `CREATE TABLE` and `CREATE INDEX` are both on its list — so a run that dies
 * partway through has already published the tables it got to and the wrapper reverses
 * nothing: the schema is left half-built. SQLite does not behave that way, and that is
 * measured rather than assumed — `CREATE TABLE`, `CREATE INDEX` and `DROP TABLE` all
 * disappear again on `ROLLBACK`, and a DDL statement neither ends the open transaction nor
 * commits the rows written before it. PostgreSQL is the same. So on two engines of three a
 * failure inside `up()` reverses cleanly.
 *
 * {@see SchemaCapabilities::supportsTransactionalDdl()} agrees with all three of those, which
 * it did not when this file was written: it answered false for SQLite as well as for MySQL,
 * and the paragraph above had to be argued around it. The capability is now measured against
 * live servers by {@see \Pulsar\Tests\Contract\TransactionalDdlContractTest}, so it states
 * the engines' behaviour rather than a cautious guess about it. Nothing below depends on it
 * either way; it is cited because it is now safe to.
 *
 * The precaution is needed regardless, on all three engines, for a reason that is not about
 * DDL at all. `runPending()` calls `recordMigration()` *after* `executeMigrationSafely()`
 * returns — after the transaction has committed. A process that dies in that window leaves a
 * schema fully built and a version unrecorded, and the next run starts again from the top
 * over it.
 *
 * Every step is therefore guarded by its own postcondition rather than by a flag two steps
 * share, and `up()` never returns on a guard: "does this table exist" gates creating it, and
 * `ensure()` asks the catalogue about the one index it is about to create. A resumed run
 * reaches the index work whether or not it created the tables — which is the failure
 * `20260805000001_totp_replay_guard_drop_purpose.php` was written to stop repeating.
 *
 * That table guard is also what makes this a no-op on a host that called `installSchema()`
 * from its own bootstrap: the table is found, nothing is created, and that host keeps the
 * shape it already has. On MySQL that shape now agrees with a fresh install on both of the
 * things that could cost data — `LONGTEXT` and `utf8mb4_bin` are what this migration builds
 * there — leaving only the `DATETIME(6)` of divergence 3, which accepts and returns the same
 * writes as the `DATETIME` beside it. On SQLite and PostgreSQL the older shape differs in the
 * ways divergences 1 to 3 describe, none of which changes a stored value. Such a fleet still
 * holds two shapes and this migration cannot reconcile them — `CREATE TABLE` cannot reshape an
 * existing table, and an ALTER written for the old shape would be wrong on every fresh
 * install. What it can do is not pretend otherwise.
 *
 * ## Rolling back does not delete rows
 *
 * `down()` drops a table only when it is empty, and otherwise raises with the table named and
 * its row count.
 *
 * The asymmetry above is the reason. Where the tables pre-existed, `up()` found them and
 * changed nothing, so dropping them reverses nothing — it destroys running workflow instances
 * and the append-only record of who moved which case to which state. On a fresh installation,
 * the only case where rolling back is meaningful, both tables are empty unless the engine has
 * been used since, and the drop is a clean reversal.
 */
return new class implements MigrationInterface {
    private const string INSTANCES_TABLE = 'workflow_instances';
    private const string TRANSITIONS_TABLE = 'workflow_transitions';
    private const string TIMEOUT_INDEX = 'idx_workflow_instances_timeout';
    private const string INSTANCE_HISTORY_INDEX = 'idx_workflow_transitions_instance';

    /**
     * The width MySQL already imposed on every identifier column, applied to all three.
     */
    private const int IDENTIFIER_LENGTH = 255;

    /**
     * The tightest bound in the schema, and the one `status` already carried on MySQL.
     * `compensating` is the longest `WorkflowInstanceStatus` value at twelve characters.
     */
    private const int STATUS_LENGTH = 32;

    public function up(ConnectionInterface $connection): void
    {
        $tables = new TableIntrospector($connection);
        $indexes = new IndexOperations($connection);

        if (!$tables->tableExists(self::INSTANCES_TABLE)) {
            $this->createTable($connection, new TableDefinition(
                name: self::INSTANCES_TABLE,
                columns: $this->instanceColumns(),
                // Set on the table and not on the columns, because that is what the
                // installer set: MySQL gives every character column the table's collation
                // unless the column overrides it, and no column here wants to disagree.
                collation: SchemaCollation::Exact,
            ));
        }

        // The timeout sweep reads `WHERE status = ? AND timeout_at IS NOT NULL AND
        // timeout_at <= ? ORDER BY timeout_at ASC`, so the column order is the one that
        // predicate wants: the equality first, the range second.
        $indexes->ensure(self::INSTANCES_TABLE, self::TIMEOUT_INDEX, ['status', 'timeout_at']);

        if (!$tables->tableExists(self::TRANSITIONS_TABLE)) {
            $this->createTable($connection, new TableDefinition(
                name: self::TRANSITIONS_TABLE,
                columns: $this->transitionColumns(),
                collation: SchemaCollation::Exact,
            ));
        }

        // `getHistory()` reads one instance's transitions in `created_at` order and
        // `reconstructState()` takes the last of them; both are served by this pair, and
        // without it each scans an append-only table.
        $indexes->ensure(self::TRANSITIONS_TABLE, self::INSTANCE_HISTORY_INDEX, ['instance_id', 'created_at']);
    }

    /**
     * Child before parent, matching the house order.
     *
     * Nothing enforces that order today — no dialect declares the foreign key, existing rows
     * may already violate one, and SQLite would need `PRAGMA foreign_keys` on to enforce it
     * at all — but a rollback on a host where one was added out of band should still succeed,
     * and the ordering costs nothing.
     *
     * The indexes need no step of their own: every supported engine drops a table's indexes
     * with the table, so reaching for `ensureAbsent()` first would only query the catalogue
     * about objects that are about to cease existing anyway.
     */
    public function down(ConnectionInterface $connection): void
    {
        // Both tables are inspected before either is dropped, so a refusal leaves the schema
        // exactly as it found it rather than half-reversed.
        $this->refuseIfPopulated($connection);

        $compiler = $this->compiler($connection);

        $this->run($connection, $compiler->compileDropTable(self::TRANSITIONS_TABLE));
        $this->run($connection, $compiler->compileDropTable(self::INSTANCES_TABLE));
    }

    /**
     * The instance row `DatabaseWorkflowStorage` inserts, column for column.
     *
     * @return list<SchemaColumn>
     */
    private function instanceColumns(): array
    {
        return [
            $this->identifier('id', primaryKey: true),
            $this->identifier('definition_id'),
            $this->counter('definition_version'),
            $this->identifier('current_state'),
            // The serialized ClassifiedContext, read back through json_decode() over the raw
            // string. Deliberately not a JSON column on any engine, even where
            // SchemaCapabilities::supportsNativeJson() would say yes: JSONB and MySQL's JSON
            // normalise whitespace, reorder object keys and drop duplicate keys, so the bytes
            // read back are not the bytes written — which is disqualifying for an
            // encrypted-context round trip.
            $this->document('context'),
            // The optimistic lock. `UPDATE ... SET version = version + 1 WHERE id = :id AND
            // version = :expected_version` is the compare-and-set, and its zero-row result is
            // what raises ConcurrentTransitionException.
            $this->counter('version'),
            $this->identifier('status', length: self::STATUS_LENGTH),
            $this->instant('started_at'),
            $this->instant('completed_at', nullable: true),
            $this->identifier('started_by'),
            $this->instant('timeout_at', nullable: true),
        ];
    }

    /**
     * The transition row `DatabaseTransitionLog` appends, column for column.
     *
     * @return list<SchemaColumn>
     */
    private function transitionColumns(): array
    {
        return [
            $this->identifier('id', primaryKey: true),
            $this->identifier('instance_id'),
            $this->identifier('from_state'),
            $this->identifier('to_state'),
            $this->identifier('transition_name'),
            $this->identifier('actor'),
            $this->note('reason'),
            $this->document('metadata'),
            $this->counter('instance_version'),
            $this->instant('created_at'),
        ];
    }

    /**
     * An identifier, a state name or an actor: bounded text, never null.
     */
    private function identifier(
        string $name,
        bool $primaryKey = false,
        int $length = self::IDENTIFIER_LENGTH,
    ): SchemaColumn {
        return new SchemaColumn(
            name: $name,
            type: SchemaColumnType::String,
            primaryKey: $primaryKey,
            length: $length,
        );
    }

    /**
     * A version number the application supplies — the workflow definition's, the
     * optimistic lock's, and the instance version a transition records.
     *
     * None of the three auto-increments: each is bound by the INSERT, or computed by the
     * `version + 1` inside the compare-and-set.
     */
    private function counter(string $name): SchemaColumn
    {
        return new SchemaColumn(name: $name, type: SchemaColumnType::Integer);
    }

    /**
     * A serialized document, whose width is decided by the data rather than by a form:
     * the instance's `ClassifiedContext` and a transition's metadata, each written and
     * read back as one JSON string.
     *
     * Wide, and not merely unbounded. {@see SchemaColumnType::Text} would compile to
     * MySQL's `TEXT`, which stops at 65,535 *bytes* — refusing the write with error 1406
     * under the shipped `sql_mode`, and truncating the row where strict mode has been
     * switched off. A `ClassifiedContext` whose values are encryptor output rather than
     * plaintext carries a nonce, a tag and base64 expansion on every field, so it reaches
     * that ceiling on a small fraction of the fields the plaintext would have needed — and
     * what is lost when it does is the state of a running case.
     * {@see SchemaColumnType::BigText} is `LONGTEXT` on MySQL, which is what the installer
     * wrote, and the same `TEXT` on SQLite and PostgreSQL, where nothing narrower was ever
     * in play.
     */
    private function document(string $name): SchemaColumn
    {
        return new SchemaColumn(name: $name, type: SchemaColumnType::BigText);
    }

    /**
     * An operator's free-text reason, and the only nullable text in either table —
     * `TransitionRecord::$reason` is optional, where `metadata` and `context` never are.
     *
     * Narrow where `document()` is wide, because the installer drew that line itself:
     * `reason` was `TEXT NULL` on MySQL in the same statement that made `metadata`
     * `LONGTEXT`. The line is in the right place — this is prose a human typed into a
     * field, not a payload whose size the data decides — and widening it would be
     * inventing a divergence rather than closing one.
     */
    private function note(string $name): SchemaColumn
    {
        return new SchemaColumn(name: $name, type: SchemaColumnType::Text, nullable: true);
    }

    /**
     * A wall-clock instant, written and read as `Y-m-d H:i:s` by both storages.
     */
    private function instant(string $name, bool $nullable = false): SchemaColumn
    {
        return new SchemaColumn(name: $name, type: SchemaColumnType::DateTime, nullable: $nullable);
    }

    /**
     * Refuse a rollback that would destroy rows this migration did not create.
     *
     * A table that is absent contributes nothing: there is nothing to drop and nothing to
     * lose, and demanding its presence would turn a partially applied migration into an
     * unrollbackable one.
     */
    private function refuseIfPopulated(ConnectionInterface $connection): void
    {
        $tables = new TableIntrospector($connection);
        $populated = [];

        foreach ([self::TRANSITIONS_TABLE, self::INSTANCES_TABLE] as $table) {
            if (!$tables->tableExists($table)) {
                continue;
            }

            $rows = $this->rowCount($connection, $table);

            if ($rows > 0) {
                $populated[] = sprintf('%s holds %d row%s', $table, $rows, $rows === 1 ? '' : 's');
            }
        }

        if ($populated !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to roll back 20260821000002_create_workflow_tables: %s. On a host '
                . 'that created these tables outside a migration, up() found them already '
                . 'there and changed nothing, so dropping them now would destroy running '
                . 'workflow instances and their transition history rather than reverse '
                . 'anything. Empty them first if the rollback is genuinely intended.',
                implode(', ', $populated),
            ));
        }
    }

    private function rowCount(ConnectionInterface $connection, string $table): int
    {
        // Delimited through the dialect, which validates the name rather than escaping it.
        // The names are constants here, and routing them through the same door as every
        // other identifier keeps that true of the next reader's copy as well.
        $result = $connection->query(
            'SELECT COUNT(*) AS c FROM ' . $connection->dialect()->quoteIdentifier($table),
        );

        foreach ($result->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }

    private function createTable(ConnectionInterface $connection, TableDefinition $definition): void
    {
        $this->run($connection, $this->compiler($connection)->compileCreate($definition));
    }

    /**
     * The variant is passed, not defaulted. MariaDB and Percona both answer the MySQL
     * driver and are told apart only by their `VERSION()` string, and a capability object
     * built without it reports for a server that did not answer.
     */
    private function compiler(ConnectionInterface $connection): DdlCompiler
    {
        return new DdlCompiler(
            $connection->driver(),
            new SchemaCapabilities($connection->driver(), $connection, $connection->variant()),
        );
    }

    /**
     * @param list<string> $statements
     */
    private function run(ConnectionInterface $connection, array $statements): void
    {
        foreach ($statements as $sql) {
            $connection->execute($sql);
        }
    }
};
