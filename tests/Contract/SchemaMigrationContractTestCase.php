<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Database\Schema\TableIntrospector;
use Pulsar\Tests\Support\FrameworkSchema;
use Throwable;

use function sort;
use function sprintf;
use function stripos;
use function strtolower;
use function substr;
use function trim;

/**
 * Shared ground for the contracts of the migrations that own the framework's own tables.
 *
 * Five migrations replaced the `installSchema()` methods the storages used to carry
 * (ADR-0043), and each is a file that has to execute on SQLite, MySQL and PostgreSQL. What
 * every one of those contracts needs is the same: a connection to each configured engine,
 * a table set wiped before and after so a run that died mid-test cannot be adopted by the
 * next one, and a way to ask the database what it actually has.
 *
 * ## The migration is required, never re-implemented
 *
 * Every subclass reaches its migration through {@see FrameworkSchema}, which requires the
 * real file. {@see TotpReplayGuardMigrationContractTest} records why in detail: a suite
 * that re-declared its migration inline passed on all three engines against a migration
 * that threw on MySQL, because the copy had left out the only method that failed. A test
 * that reproduces the code under test cannot disagree with it.
 *
 * ## The questions are asked portably
 *
 * `tableExists`, `columnExists` and `indexExists` go through {@see TableIntrospector} and
 * {@see IndexOperations} rather than through three hand-written catalogue queries. Those
 * two are production code with contract tests of their own, and using them here means a
 * test asserting "the index is there" is asking the same question, of the same catalogue,
 * with the same scoping, as the migration that created it.
 *
 * Where a claim cannot be read portably — that the engine *enforces* a key, that a column
 * *rejects* NULL — the subclass asserts it behaviourally, by attempting the write that
 * must fail. That is the stronger assertion anyway: a catalogue row saying PRIMARY KEY and
 * an engine that refuses a duplicate are not the same fact, and only the second is what
 * the storage depends on.
 *
 * Four questions have neither route and are asked of each engine's catalogue directly.
 * They are the ones where the portable layer, by design, exposes nothing that would
 * answer them — and each of them guards a repair that a suite without it could not tell
 * from its own absence:
 *
 *   - {@see indexColumns()} — what an index is actually built over. No behaviour
 *     distinguishes a correct index from a wrongly-shaped one; both answer every query,
 *     one of them slowly.
 *   - {@see indexPredicate()} — what an index was narrowed to. Same reason, one step
 *     further: a partial index without its predicate covers more rows and returns the
 *     same ones.
 *   - {@see columnType()} — what a column actually is. `columnExists()` cannot separate a
 *     column that holds four gibibytes from one that stops at 65,535 bytes, and on MySQL
 *     that is the difference between `BigText` and `Text`.
 *   - {@see assertExactCollation()} — how the table compares text. The behavioural half of
 *     that claim *is* assertable everywhere, and {@see assertCaseVariantKeysCoexist()}
 *     asserts it; the clause that produces it exists on one engine and is read there.
 *
 * Each of those helpers states which engines its assertion can actually fail on, because
 * three of them can only fail on one. That is the shape of the underlying facts — MySQL is
 * the engine whose defaults are wrong and whose text types differ — and a reader who takes
 * "runs on three engines" for "proven on three engines" would be wrong in a way that
 * matters.
 */
abstract class SchemaMigrationContractTestCase extends TestCase
{
    private ?ConnectionInterface $connection = null;

    /**
     * @return iterable<string, array{Driver}>
     */
    public static function engines(): iterable
    {
        foreach (DatabaseEngine::all() as $driver) {
            yield $driver->value => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->connection = null;
    }

    /**
     * The tables this contract's migration owns, in an order safe to drop.
     *
     * @return list<string>
     */
    abstract protected function tables(): array;

    /**
     * The path constant on {@see FrameworkSchema} naming the file under test.
     */
    abstract protected function migrationPath(): string;

    protected function migration(): MigrationInterface
    {
        return FrameworkSchema::load($this->migrationPath());
    }

    /**
     * Connect, and start from a database that does not have these tables.
     *
     * The wipe is not tidiness. Every migration here guards its `CREATE` on the table
     * being absent, so a leftover table from a run that died mid-test would be adopted
     * silently and the test would assert against a shape this migration never produced.
     */
    protected function engine(Driver $driver): ConnectionInterface
    {
        if (!DatabaseEngine::isConfigured($driver)) {
            self::markTestSkipped(DatabaseEngine::absenceReason($driver));
        }

        $connection = DatabaseEngine::connect($driver);
        $this->connection = $connection;
        $this->dropTables();

        // The local, not the property. Reading the property back would hand PHPStan a
        // nullable it cannot narrow — and would be a genuine lie the moment tearDown()
        // or a helper cleared it between the assignment and the return.
        return $connection;
    }

    protected function tableExists(ConnectionInterface $connection, string $table): bool
    {
        return new TableIntrospector($connection)->tableExists($table);
    }

    protected function columnExists(ConnectionInterface $connection, string $table, string $column): bool
    {
        return new TableIntrospector($connection)->columnExists($table, $column);
    }

    protected function indexExists(ConnectionInterface $connection, string $table, string $index): bool
    {
        return new IndexOperations($connection)->exists($table, $index);
    }

    /**
     * The columns the named index is built over, in key order.
     *
     * The question this batch of contracts could not ask, and the reason a repair could be
     * deleted with the suite staying green: {@see IndexOperations::exists()} decides by
     * name, and so does {@see IndexOperations::ensure()}. An index carrying the right name
     * over the wrong columns is therefore indistinguishable from the right one to every
     * portable check the framework has — including the one `ensure()` itself makes before
     * deciding it has nothing to do. A migration that rebuilds such an index has no
     * observable effect at all unless somebody reads the catalogue back.
     *
     * There is no portable spelling, so this is asked per engine. SQLite keeps the answer
     * in `pragma_index_info`, MySQL in `information_schema.statistics`, PostgreSQL in
     * `pg_index.indkey` — an array of attribute numbers whose *order* is the key order,
     * which is why it is unnested with ordinality rather than joined on membership: a plain
     * `attnum = ANY(indkey)` returns the right columns in the catalogue's order rather than
     * the index's, and would call a reordered key correct.
     *
     * Naming engines here is what a test is allowed to do —
     * {@see \Pulsar\Tests\Unit\Integrity\DriverDispatchRatchetTest} scopes its ban to
     * production code precisely so a test can reach a catalogue the portable layer does not
     * expose. Promoting this to {@see \Pulsar\Database\Schema\TableIntrospector} was the
     * alternative, and it would be a third public spelling of "what shape is this index"
     * that only tests call; the framework's own migrations have no use for it, because a
     * migration that has to compare an index shape should drop and rebuild instead.
     *
     * @return list<string>
     */
    protected function indexColumns(ConnectionInterface $connection, string $table, string $index): array
    {
        $sql = match ($connection->driver()) {
            Driver::SQLite => 'SELECT name AS col FROM pragma_index_info(:index) ORDER BY seqno',
            Driver::MySQL => 'SELECT column_name AS col FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index '
                . 'ORDER BY seq_in_index',
            Driver::PostgreSQL => 'SELECT a.attname AS col FROM pg_index x '
                . 'JOIN pg_class i ON i.oid = x.indexrelid '
                . 'CROSS JOIN LATERAL unnest(x.indkey) WITH ORDINALITY AS k(attnum, ord) '
                . 'JOIN pg_attribute a ON a.attrelid = x.indrelid AND a.attnum = k.attnum '
                . 'WHERE x.indrelid = to_regclass(:table) AND i.relname = :index ORDER BY k.ord',
        };

        $bindings = $connection->driver() === Driver::SQLite
            ? ['index' => $index]
            : ['table' => $table, 'index' => $index];

        $columns = [];

        foreach ($connection->query($sql, $bindings)->rows as $row) {
            $columns[] = $row->getString('col');
        }

        return $columns;
    }

    protected function rowCount(ConnectionInterface $connection, string $table): int
    {
        foreach ($connection->query('SELECT COUNT(*) AS c FROM ' . $table)->rows as $row) {
            return $row->getInt('c');
        }

        return 0;
    }

    /**
     * The type the engine actually recorded for a column, spelled as its own catalogue
     * spells it.
     *
     * The portable layer has no way to ask this, and that is the whole reason the question
     * has to be asked per engine. {@see TableIntrospector::columnExists()} answers whether
     * a column is there, which is the same answer for a column that can hold four
     * gibibytes and one that stops at 65,535 bytes — and on MySQL those two are what
     * {@see \Pulsar\Database\Schema\SchemaColumnType::BigText} and `::Text` compile to. A
     * suite built only from `columnExists()` therefore cannot tell a payload column from a
     * column that will refuse the payload, which is precisely the narrowing the portable
     * rewrite introduced and the repair closed.
     *
     * The catalogues disagree about spelling, not about the fact: MySQL's `DATA_TYPE` is
     * `longtext`/`text`/`varchar`, PostgreSQL's `data_type` is `text`/`character varying`,
     * and SQLite's `pragma_table_info` hands back the declared type verbatim, `TEXT`. The
     * raw string is returned; the callers below fold case before comparing, because a
     * server that answers in upper case is answering the same question.
     */
    protected function columnType(ConnectionInterface $connection, string $table, string $column): string
    {
        $sql = match ($connection->driver()) {
            Driver::SQLite => 'SELECT type AS t FROM pragma_table_info(:table) WHERE name = :column',
            Driver::MySQL => 'SELECT data_type AS t FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            Driver::PostgreSQL => 'SELECT data_type AS t FROM information_schema.columns '
                . 'WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column',
        };

        foreach ($connection->query($sql, ['table' => $table, 'column' => $column])->rows as $row) {
            return $row->getString('t');
        }

        self::fail(sprintf(
            'No catalogue row for %s.%s on %s — the column the type assertion is about does not exist.',
            $table,
            $column,
            $connection->driver()->value,
        ));
    }

    /**
     * A column that must hold whatever the data decided, at the widest ceiling the engine
     * offers.
     *
     * **Only MySQL can fail this.** That is not a weakness of the assertion, it is the
     * shape of the defect: `BigText` and `Text` are one keyword apart on MySQL — `LONGTEXT`
     * against a `TEXT` that stops at 65,535 *bytes* — and the same keyword everywhere else,
     * because PostgreSQL's and SQLite's `TEXT` is already as wide as either engine goes.
     * Read on PostgreSQL or SQLite this assertion pins what the type compiles to and would
     * catch a compiler that started emitting something else; it cannot, and never will,
     * distinguish the repaired migration from the unrepaired one. The engine that can is
     * the engine the repair was for.
     *
     * The behavioural half — a payload past 64 KiB written and read back whole — is
     * asserted by the migration's own contract, and has the same one-engine honesty.
     */
    protected function assertWideText(
        ConnectionInterface $connection,
        string $table,
        string $column,
        string $why,
    ): void {
        $expected = match ($connection->driver()) {
            Driver::MySQL => 'longtext',
            Driver::PostgreSQL, Driver::SQLite => 'text',
        };

        self::assertSame(
            $expected,
            strtolower($this->columnType($connection, $table, $column)),
            sprintf('%s.%s must be the wide text type: %s', $table, $column, $why),
        );
    }

    /**
     * A column whose width a form decided, left narrow on purpose.
     *
     * The counterweight to {@see assertWideText()}, and the reason that assertion is not
     * vacuous. A table where every text column reads `longtext` on MySQL would satisfy
     * `assertWideText()` while proving only that the compiler has one text type; asserting
     * that the columns the migration deliberately left narrow are still `text` is what
     * shows the distinction is real, is made per column, and lands where the migration said
     * it lands.
     *
     * Same honesty as its counterpart: on PostgreSQL and SQLite both types compile to
     * `TEXT`, so only MySQL can tell these two assertions apart.
     */
    protected function assertNarrowText(
        ConnectionInterface $connection,
        string $table,
        string $column,
        string $why,
    ): void {
        self::assertSame(
            'text',
            strtolower($this->columnType($connection, $table, $column)),
            sprintf('%s.%s must be the narrow text type: %s', $table, $column, $why),
        );
    }

    /**
     * The table compares text byte for byte, asked of each engine's own catalogue.
     *
     * Why it matters is the same on all three and is enforced on all three by
     * {@see assertCaseVariantKeysCoexist()}: these tables key on identifiers, and under a
     * case-insensitive comparison two ids differing only in case are one key — so the
     * second insert either collides or, through an upsert, silently overwrites the first,
     * and a stamp written `WHERE id = :id` can land on a row nobody named.
     *
     * What can be *read back* differs sharply by engine, so this splits three ways rather
     * than pretending to one assertion:
     *
     *   - **MySQL** is the only engine that has a clause here and the only one whose
     *     default is wrong: `utf8mb4_0900_ai_ci` on 8.0, `utf8mb4_general_ci` on the forks.
     *     Both the table's collation and the character columns' are read and required to be
     *     `utf8mb4_bin`. Deleting `collation: SchemaCollation::Exact` from the migration
     *     fails here, and only here.
     *   - **PostgreSQL** takes no clause and needs none. What is asserted is that no clause
     *     was emitted — a non-null `collation_name` would mean the compiler wrote something
     *     the enum promises it does not — and that the database's default collation is
     *     deterministic, which is the property that makes equality on `text` fall through to
     *     a byte comparison.
     *   - **SQLite** likewise: the table's declared SQL must carry no `COLLATE`, leaving the
     *     default `BINARY` sequence, and the engine is asked directly whether it considers
     *     two case variants equal.
     *
     * Neither of the last two can fail when the request is deleted from the migration,
     * because neither engine was ever given anything to delete. Their half of the guarantee
     * is the engine's, and it is asserted here as the engine's.
     *
     * @param list<string> $characterColumns Columns MySQL must have applied the collation
     *                                       to — the keys and the identifiers, not the
     *                                       integers, which MySQL refuses to collate at all.
     */
    protected function assertExactCollation(
        ConnectionInterface $connection,
        string $table,
        array $characterColumns,
    ): void {
        match ($connection->driver()) {
            Driver::MySQL => $this->assertMySqlCollatesExactly($connection, $table, $characterColumns),
            Driver::PostgreSQL => $this->assertPostgresNeededNoCollationClause($connection, $table, $characterColumns),
            Driver::SQLite => $this->assertSqliteNeededNoCollationClause($connection, $table),
        };
    }

    /**
     * Two identifiers differing only in case are two rows, proven by writing both.
     *
     * The catalogue half of the collation claim is readable on one engine
     * ({@see assertExactCollation()}); this half is the claim itself and it is made on all
     * three. `AB` and `ab` are the whole fixture: under MySQL's shipped
     * `utf8mb4_0900_ai_ci` they are the same primary key, so one of two things happens and
     * both are caught here — a plain INSERT is rejected as a duplicate, or an upsert quietly
     * folds the second write onto the first row and one of the two records ceases to exist.
     *
     * The write is the caller's, through the real storage rather than through hand-written
     * SQL, because the collision is only interesting where the application will meet it: in
     * the statement the storage actually issues.
     *
     * A rejected write is turned into a failure rather than allowed to surface as an error,
     * so the reason is in the report instead of in a driver's exception text.
     *
     * @param callable(string): void $write Writes one row keyed by the id it is given.
     */
    protected function assertCaseVariantKeysCoexist(
        ConnectionInterface $connection,
        string $table,
        string $keyColumn,
        callable $write,
    ): void {
        foreach (['AB', 'ab'] as $key) {
            try {
                $write($key);
            } catch (Throwable $e) {
                self::fail(sprintf(
                    '%s refused %s: it is being compared to a key differing from it only in case, '
                    . 'which means %s.%s is not collated byte-exactly. %s',
                    $table,
                    $key,
                    $table,
                    $keyColumn,
                    $e->getMessage(),
                ));
            }
        }

        $stored = [];

        foreach ($connection->query(sprintf('SELECT %s AS k FROM %s', $keyColumn, $table))->rows as $row) {
            $stored[] = $row->getString('k');
        }

        sort($stored);

        self::assertSame(
            ['AB', 'ab'],
            $stored,
            sprintf(
                'AB and ab are two identifiers, so %s must hold two rows. One row means the key '
                . 'folded them together — on MySQL that is the server default doing it, and the '
                . 'record that vanished is the one written second.',
                $table,
            ),
        );
    }

    /**
     * The predicate an index was built with, or null where the engine has none.
     *
     * The partial-index counterpart to {@see indexColumns()}, and unobservable for the same
     * reason: {@see IndexOperations::ensure()} decides existence by name, so an index
     * created without its `WHERE` clause carries the right name over the right columns and
     * differs only in how many rows it covers. No query returns different rows because of
     * it, and no portable check the framework has can see it, so the catalogue is again the
     * only witness.
     *
     * MySQL returns null unconditionally, and that is the true answer rather than a gap:
     * `MySqlDialect::supportsPartialIndexes()` is false and the dialect drops the predicate
     * before compiling, which is why the migration puts the filtered columns at the head of
     * the key there instead. The engines that keep the predicate spell it back differently —
     * SQLite verbatim from the `CREATE INDEX` it was given, PostgreSQL normalised by
     * `pg_get_expr()` into its own parenthesised form — so callers match on what the
     * predicate names, never on the exact string.
     */
    protected function indexPredicate(ConnectionInterface $connection, string $table, string $index): ?string
    {
        if ($connection->driver() === Driver::MySQL) {
            return null;
        }

        if ($connection->driver() === Driver::PostgreSQL) {
            $sql = 'SELECT pg_get_expr(x.indpred, x.indrelid) AS p FROM pg_index x '
                . 'JOIN pg_class i ON i.oid = x.indexrelid '
                . 'WHERE x.indrelid = to_regclass(:table) AND i.relname = :index';

            foreach ($connection->query($sql, ['table' => $table, 'index' => $index])->rows as $row) {
                return $row->getNullableString('p');
            }

            return null;
        }

        $definition = $this->sqliteObjectSql($connection, 'index', $index);
        $where = stripos($definition, ' WHERE ');

        return $where === false ? null : trim(substr($definition, $where + 7));
    }

    /**
     * MySQL keeps a collation on the table and one on every character column, and both are
     * read: the table's is what the migration asked for, the columns' is where the key is
     * actually compared.
     *
     * @param list<string> $characterColumns
     */
    private function assertMySqlCollatesExactly(
        ConnectionInterface $connection,
        string $table,
        array $characterColumns,
    ): void {
        self::assertSame(
            'utf8mb4_bin',
            $this->catalogueString(
                $connection,
                'SELECT table_collation AS v FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :table',
                ['table' => $table],
            ),
            sprintf(
                '%s must be created COLLATE=utf8mb4_bin. MySQL 8 defaults to utf8mb4_0900_ai_ci, '
                . 'which is case- and accent-insensitive, and this table keys on an identifier.',
                $table,
            ),
        );

        foreach ($characterColumns as $column) {
            self::assertSame(
                'utf8mb4_bin',
                $this->catalogueString(
                    $connection,
                    'SELECT collation_name AS v FROM information_schema.columns '
                    . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
                    ['table' => $table, 'column' => $column],
                ),
                sprintf(
                    '%s.%s inherits the table collation, and a column comparing case-insensitively '
                    . 'is where an identifier stops being one.',
                    $table,
                    $column,
                ),
            );
        }
    }

    /**
     * PostgreSQL is asked two things, neither of which the migration can get wrong by
     * omission: that nothing was emitted, and that the default it falls back to is the one
     * the enum says it is.
     *
     * @param list<string> $characterColumns
     */
    private function assertPostgresNeededNoCollationClause(
        ConnectionInterface $connection,
        string $table,
        array $characterColumns,
    ): void {
        foreach ($characterColumns as $column) {
            self::assertNull(
                $this->catalogueNullableString(
                    $connection,
                    'SELECT collation_name AS v FROM information_schema.columns '
                    . 'WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column',
                    ['table' => $table, 'column' => $column],
                ),
                sprintf(
                    '%s.%s must carry no explicit collation: SchemaCollation::Exact promises '
                    . 'PostgreSQL is emitted nothing, and a clause here would be the compiler '
                    . 'writing something the enum says it does not.',
                    $table,
                    $column,
                ),
            );
        }

        self::assertSame(
            1,
            $this->catalogueInt(
                $connection,
                "SELECT CASE WHEN collisdeterministic THEN 1 ELSE 0 END AS v FROM pg_collation WHERE collname = 'default'",
            ),
            'the database default collation has to be deterministic, which is what makes equality '
            . 'on text fall through to a byte comparison once the locale calls two values a tie',
        );
    }

    /**
     * SQLite is asked the same two things: that the declared SQL names no collating
     * sequence, and that the default one it therefore uses is `BINARY` rather than
     * `NOCASE`.
     */
    private function assertSqliteNeededNoCollationClause(ConnectionInterface $connection, string $table): void
    {
        self::assertStringNotContainsStringIgnoringCase(
            'COLLATE',
            $this->sqliteObjectSql($connection, 'table', $table),
            sprintf(
                '%s must be declared with no COLLATE clause, so its columns take the default '
                . 'BINARY sequence. NOCASE has to be asked for by name, which is why silence here '
                . 'cannot be mistaken for it.',
                $table,
            ),
        );

        self::assertSame(
            0,
            $this->catalogueInt($connection, "SELECT ('AB' = 'ab') AS v"),
            'SQLite must compare two case variants as different text under its default sequence',
        );
    }

    /**
     * The `CREATE` statement SQLite recorded for one object, which is the only place the
     * collating sequence and the partial-index predicate survive on that engine.
     */
    private function sqliteObjectSql(ConnectionInterface $connection, string $type, string $name): string
    {
        $sql = 'SELECT sql AS v FROM sqlite_master WHERE type = :type AND name = :name';

        foreach ($connection->query($sql, ['type' => $type, 'name' => $name])->rows as $row) {
            return $row->getString('v');
        }

        self::fail(sprintf('sqlite_master has no %s named %s', $type, $name));
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function catalogueString(ConnectionInterface $connection, string $sql, array $bindings = []): string
    {
        $value = $this->catalogueNullableString($connection, $sql, $bindings);

        // Not `assertNotNull()`: this narrows for the reader and for static analysis alike,
        // where the assertion would only narrow for the reader.
        if ($value === null) {
            self::fail(sprintf('the catalogue answered NULL to: %s', $sql));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function catalogueNullableString(
        ConnectionInterface $connection,
        string $sql,
        array $bindings = [],
    ): ?string {
        foreach ($connection->query($sql, $bindings)->rows as $row) {
            return $row->getNullableString('v');
        }

        self::fail(sprintf('the catalogue returned no row at all for: %s', $sql));
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function catalogueInt(ConnectionInterface $connection, string $sql, array $bindings = []): int
    {
        foreach ($connection->query($sql, $bindings)->rows as $row) {
            return $row->getInt('v');
        }

        self::fail(sprintf('the catalogue returned no row at all for: %s', $sql));
    }

    /**
     * Drop this contract's tables, tolerating every state a failed run can leave.
     *
     * `DROP TABLE IF EXISTS` is written out rather than routed through the compiler
     * because the compiler is one of the things under test here, and a teardown that
     * shares a defect with the code it cleans up after leaves debris that the next test
     * then adopts.
     */
    private function dropTables(): void
    {
        if ($this->connection === null) {
            return;
        }

        foreach ($this->tables() as $table) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
}
