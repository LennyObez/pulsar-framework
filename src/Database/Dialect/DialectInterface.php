<?php

declare(strict_types=1);

namespace Pulsar\Database\Dialect;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\LockMode;

/**
 * How one database engine wants its SQL written.
 *
 * ## Why this is public, and why `Driver` should not be
 *
 * {@see Driver} answers exactly one question: which PDO driver opens this connection. It
 * is an identity. The moment application code writes `match ($driver)` it has taken on a
 * second job — knowing every engine the framework will ever support — and that is the job
 * that makes adding an engine a breaking change instead of an additive one. An exhaustive
 * match throws `UnhandledMatchError` the first time it meets a case that did not exist
 * when it was written, and the framework cannot see, let alone fix, the matches written
 * in someone else's application.
 *
 * This interface removes the reason to write one. Ask what you need — how to delimit an
 * identifier, how to express a limit, whether `RETURNING` is available — and a new engine
 * becomes a new implementation of this contract rather than a new arm in everybody's
 * code.
 *
 * ## What belongs here, and what belongs in SchemaCapabilities
 *
 * The split is version-dependence. This interface describes the SQL a given engine
 * *family* accepts, which is a property of the engine and needs no connection to
 * establish. {@see \Pulsar\Database\Schema\SchemaCapabilities} answers what a specific
 * running *server* supports, which it establishes by asking it — MySQL gained common
 * table expressions in 8.0 and MariaDB in 10.2, so only the server can settle that.
 *
 * When both could answer, prefer capabilities: a dialect that guesses a version is a
 * dialect that will be wrong about somebody's deployment.
 *
 * @api
 */
#[Api(since: '1.0.0')]
interface DialectInterface
{
    /**
     * The PDO driver this dialect writes for.
     *
     * Identity, not dispatch. Exposed so a caller can log or display which engine it is
     * talking to — not so it can branch on the answer.
     */
    public function driver(): Driver;

    /**
     * Which member of the engine family this is, where the family has more than one.
     *
     * MariaDB and Percona both connect through the MySQL driver and are told apart by
     * their `VERSION()` string, not by their DSN.
     */
    public function variant(): DriverVariant;

    /**
     * Validate an identifier and delimit it the way this engine expects.
     *
     * Validation, not escaping. The two are not interchangeable: escaping accepts any
     * name and stakes correctness on doubling the delimiter correctly everywhere, while
     * validation refuses any name that could carry a delimiter, a comment introducer or a
     * statement separator at all. Identifiers cannot be bound as parameters, so the
     * conservative answer is the correct one — and the name arriving here has often come
     * from a request, as a sort field or a decoded pagination cursor.
     *
     * @throws InvalidArgumentException If the identifier is not valid on every supported
     *                                   engine.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Compile a `LIMIT` / `OFFSET` clause, including the leading space, or an empty
     * string when neither bound is set.
     */
    public function compileLimitOffset(?int $limit, ?int $offset): string;

    /**
     * Compile a row-lock clause, including the leading space.
     *
     * Returns an empty string for {@see LockMode::None}, and for an engine that cannot
     * lock rows at all. Check {@see supportsRowLocking()} before relying on the lock:
     * an empty clause reads exactly like "no lock requested", so a caller that does not
     * ask will proceed believing it holds something it does not.
     */
    public function compileLock(LockMode $mode): string;

    /**
     * Whether this engine takes row-level locks at all.
     *
     * False for SQLite, which serialises writes at the database level instead. Code whose
     * correctness depends on `FOR UPDATE` must branch on this rather than assume.
     */
    public function supportsRowLocking(): bool;

    /**
     * The expression yielding the current timestamp.
     */
    public function currentTimestamp(): string;

    /**
     * Compile a boolean literal — engines disagree on whether that is a keyword or a
     * digit.
     */
    public function compileBooleanLiteral(bool $value): string;

    /**
     * Whether `INSERT … RETURNING` is available.
     *
     * Reports what the engine family supports at the version this framework requires.
     * Where a specific server's version decides it, ask SchemaCapabilities instead.
     */
    public function supportsReturning(): bool;

    /**
     * Whether `CREATE INDEX` accepts an `IF NOT EXISTS` clause.
     *
     * MySQL never added it; MariaDB, PostgreSQL and SQLite have it. This is the single
     * most repeated engine test in the migration corpus, and getting it wrong is not a
     * degradation but a parse error, so it is asked here rather than rediscovered in
     * every migration.
     */
    public function supportsIndexIfNotExists(): bool;

    /**
     * Compile a `CREATE INDEX`.
     *
     * The single most repeated statement in a migration corpus, and the one where the
     * engines disagree most cheaply — which is why it is compiled here rather than
     * rediscovered in every migration that needs one.
     *
     * `$ifNotExists` is a request, not a guarantee. An engine that cannot express it
     * (MySQL) emits the plain statement, so the migration is **not** idempotent there and
     * re-running it raises a duplicate-index error. A migration that must be re-runnable
     * on MySQL has to check `information_schema` itself; ask
     * {@see supportsIndexIfNotExists()} to find out whether that is necessary.
     *
     * `$where` is a request on the same terms. A partial index covers only the rows the
     * predicate admits, which on a table whose interesting rows are a small minority — an
     * outbox, where all but the unpublished have been dealt with — is the difference
     * between an index that stays small and one that grows with the table forever. An
     * engine without partial indexes emits the plain statement and gets a full index:
     * larger, and chosen differently by the planner, but never a wrong answer. Ask
     * {@see supportsPartialIndexes()} when the distinction matters enough to branch on.
     *
     * The predicate is emitted as given. It is not a place for user input.
     *
     * A column may be a name or an {@see \Pulsar\Database\Schema\IndexColumn}, which
     * carries the order the rows are stored in. That matters where a listing reads
     * "newest first": a descending index is scanned forwards and can stop early, an
     * ascending one is scanned backwards and cannot.
     *
     * @param list<string|\Pulsar\Database\Schema\IndexColumn> $columns
     */
    public function compileCreateIndex(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        bool $ifNotExists = false,
        ?string $where = null,
    ): string;

    /**
     * Whether `CREATE INDEX … WHERE …` is available.
     *
     * SQLite has had partial indexes since 3.8.0 and PostgreSQL for far longer; MySQL has
     * none, and no syntax that approximates one. Unlike `IF NOT EXISTS`, getting this
     * wrong costs no error — the index is simply wider than asked for — which is exactly
     * why a caller that depends on the narrowing has to ask rather than assume.
     */
    public function supportsPartialIndexes(): bool;

    /**
     * Whether an index column accepts `NULLS FIRST` or `NULLS LAST`.
     *
     * PostgreSQL and SQLite do; MySQL has no such clause and no way to say it otherwise,
     * so an index that depends on where its NULLs sit cannot be expressed there. The cost
     * of the difference is a scan direction, never a wrong row — but a caller ordering a
     * nullable column deliberately is entitled to know which engines honoured it.
     *
     * Distinct from the direction itself: `DESC` is accepted everywhere the framework
     * supports, MySQL having honoured it since 8.0 and merely parsed it before.
     */
    public function supportsNullsOrdering(): bool;

    /**
     * Compile a `DROP INDEX`.
     *
     * Two differences hide here, not one: MySQL needs the table named on the statement,
     * and it accepts no `IF EXISTS`. A migration that writes the standard form by hand
     * therefore fails on MySQL for a reason that has nothing to do with the index.
     */
    public function compileDropIndex(string $name, string $table): string;

    /**
     * Compile the query that answers whether an index exists.
     *
     * The companion {@see compileCreateIndex()} needs: on an engine without
     * `IF NOT EXISTS` the caller has to establish absence before creating, and on every
     * engine it has to establish presence before dropping, since no engine spells
     * `DROP INDEX IF EXISTS` the same way twice. Each engine keeps that answer in a
     * different catalogue — `information_schema.statistics`, `pg_indexes`,
     * `sqlite_master` — so the query belongs with the rest of the engine's spelling.
     *
     * The result is a single row with one column, `c`, holding a count. Bind exactly
     * two named parameters: `table` and `index`.
     *
     * Left unimplemented by {@see AbstractDialect} on purpose. There is no reasonable
     * default — the three catalogues share neither name nor shape — and an engine added
     * without an answer here would silently report every index absent, which reads as
     * "create it again" and fails on the duplicate.
     */
    public function compileIndexExists(): string;

    /**
     * Compile the query that answers whether a table exists.
     *
     * The result is a single row with one column, `c`, holding a count. Bind one named
     * parameter: `table`.
     *
     * A migration that alters a table it does not own has to establish that the table is
     * there first, and the answer decides between proceeding and refusing — so reporting
     * absence wrongly is not a missed optimisation, it is a migration recorded as applied
     * over a schema it never touched. Each engine keeps the answer in its own catalogue,
     * which is why this cannot be written once by the caller.
     *
     * Left unimplemented by {@see AbstractDialect}, on the same reasoning as
     * {@see compileIndexExists()}.
     */
    public function compileTableExists(): string;

    /**
     * Compile the query that answers whether a column exists on a table.
     *
     * The result is a single row with one column, `c`, holding a count. Bind exactly two
     * named parameters: `table` and `column`.
     *
     * This is what makes a schema change re-runnable. A migration is recorded only once
     * `up()` has returned, so one that dies partway runs again from the top and must be
     * able to see which of its steps already happened.
     *
     * Left unimplemented by {@see AbstractDialect}, on the same reasoning as
     * {@see compileIndexExists()}.
     */
    public function compileColumnExists(): string;

    /**
     * Compile the query that answers whether a table carries a primary key.
     *
     * The result is a single row with one column, `c`, holding a count. Bind one named
     * parameter: `table`. Any positive count means a key exists; the number is not the
     * number of keys, which is always zero or one, but of columns participating in it.
     *
     * Asked as its own postcondition rather than inferred from a step that was supposed
     * to add one: a run interrupted between dropping a column and adding the key leaves a
     * table whose uniqueness nothing enforces, and a guard that reads it back is the only
     * thing that notices.
     *
     * Left unimplemented by {@see AbstractDialect}, on the same reasoning as
     * {@see compileIndexExists()}.
     */
    public function compilePrimaryKeyExists(): string;

    /**
     * Compile a statement leaving at most one row per distinct key, or null when the
     * engine cannot express it.
     *
     * Required before narrowing a primary key: a table holding two rows that agree on the
     * new key columns refuses the key, and on a resumed migration it refuses it every time
     * thereafter.
     *
     * Deciding which copy survives needs something that separates rows the key does not.
     * Give `$discriminator` a column whose values order the duplicates and every engine
     * can express it — that column supplies the total order. Without one, the engine's own
     * per-row identity has to serve, and only two of the three expose one: SQLite's
     * `rowid` and PostgreSQL's `ctid`. MySQL has neither, answers null, and leaves the
     * caller to rebuild the table from a grouped read.
     *
     * Null is therefore a statement about what the engine can express, not about which
     * engine it is — the only form in which that difference need be visible outside this
     * layer.
     *
     * @param list<string> $keyColumns    Columns whose combination must become unique.
     * @param ?string      $discriminator Column ordering the duplicates, when one exists.
     */
    public function compileCollapseDuplicates(
        string $table,
        array $keyColumns,
        ?string $discriminator = null,
    ): ?string;

    /**
     * Turn an `INSERT` into an upsert.
     *
     * @param list<string> $conflictColumns Columns forming the conflict target.
     * @param list<string> $updateColumns   Columns to overwrite when the row exists.
     */
    public function compileUpsert(string $insertSql, array $conflictColumns, array $updateColumns): string;

    /**
     * A short stable name for this dialect, for logs and diagnostics.
     */
    public function name(): string;
}
