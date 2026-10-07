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
use Pulsar\Database\SqlIdentifier;

/**
 * Give the dead-letter store a table that exists before the first job fails.
 *
 * `failed_jobs` is where {@see \Pulsar\Queue\DeadLetterQueue::store()} puts a job that has
 * exhausted its retries. In the domains this framework targets, that row *is* the point:
 * it is what an operator inspects and retries, and what an auditor later asks to see.
 * The table used to be created by `DatabaseFailedJobRepository::installSchema()`; that
 * method no longer exists, and this file is the only place the table is defined.
 *
 * ## Why the installer had to go
 *
 * Two reasons. The first is specific to this table; the second is the one that generalises.
 *
 * **Nothing called it.** {@see \Pulsar\Core\Wiring\QueueWiring} builds the repository from
 * the connection manager and binds it as `FailedJobRepositoryInterface` without ever
 * installing a schema, and no console command provisions the table either — the only
 * caller anywhere in the repository was a unit test's `setUp()`. So on every real
 * deployment the first use of `failed_jobs` was a worker dead-lettering a job into a table
 * that did not exist. The record is lost at exactly the moment it was supposed to be
 * created, and that failure is the quietest kind there is: the machinery that would have
 * reported it is the machinery that just broke.
 *
 * **And where an installer of this shape *was* called, it was called from boot** — the
 * event outbox did that — which is the worse posture of the two. It means the role the
 * application connects with must hold CREATE, on every boot, forever. A deployment for
 * banking or healthcare wants the opposite: the request-handling role reads and writes
 * rows and can do nothing at all to the shape of the schema, so that an injected statement
 * or a compromised worker cannot create, alter or drop a table, and so that schema change
 * is a reviewed, versioned, named event rather than a side effect of a process restart.
 * Boot-time DDL also races — N containers starting together issue the same DDL
 * concurrently — where `pulsar migrate` takes an advisory lock and runs once. Recording
 * the change is the other half of it: a migration leaves a row saying which version was
 * applied and when, and an installer that runs at boot leaves nothing behind at all.
 *
 * Holding the DDL in two places would be its own defect regardless. An installer and a
 * migration drift the first time a column changes, and no test can see it. There is now
 * one definition of this table, and this file is it.
 *
 * ## One definition, compiled per engine
 *
 * {@see definition()} states the table once. {@see DdlCompiler} turns it into the DDL each
 * engine accepts, and {@see IndexOperations} does the same for the index. Nothing here
 * names an engine.
 *
 * An earlier draft of this migration transcribed three `CREATE TABLE` statements and chose
 * between them. {@see \Pulsar\Tests\Unit\Integrity\DriverDispatchRatchetTest} rejects that
 * shape and is right to: a caller that decides behaviour by naming engines has taken on
 * knowing every engine the framework will ever support, which turns adding one into a
 * breaking change instead of an additive one. Branching on the dialect's *name* rather
 * than on `Driver` would have evaded the grep without fixing anything — the same three
 * transcriptions, now invisible to the guard that exists to count them.
 *
 * ## Where the compiled DDL differs from the text the installer wrote
 *
 * The compiler emits column types from {@see SchemaColumnType} and the one table option
 * {@see SchemaCollation} can express. That is not identical to what the installer
 * produced, and what is left over is set out here rather than left to be discovered.
 * These are reachable only on a **fresh** database: where the installer already ran, the
 * table exists, the create below is skipped and what is on disk is untouched.
 *
 *   - **PostgreSQL and SQLite `id`, `queue`, `job_class`: `TEXT` becomes
 *     `VARCHAR(255)`.** `String` is the only portable type that MySQL will accept as a
 *     primary key — `TEXT` there is error 1170, a key with no prefix length — and one
 *     `SchemaColumnType` maps to one type per engine, so the other two engines get the
 *     bounded form too. On SQLite this is cosmetic: `VARCHAR(255)` carries TEXT affinity
 *     and no length is enforced. On PostgreSQL the 255-character cap is real and an
 *     over-long value raises SQLSTATE 22001. Nothing the framework generates approaches
 *     it — job ids are `bin2hex(random_bytes(16))`, 32 characters — but a queue driver
 *     supplying its own ids is not bound by that.
 *   - **SQLite `id` gains `NOT NULL`.** SQLite is alone in *not* implying it from
 *     `PRIMARY KEY`, so the installer's `id TEXT PRIMARY KEY` accepted a NULL id there.
 *     This is a difference in the strict direction and is left standing deliberately.
 *   - **`ENGINE=InnoDB` is not emitted, and it is the only one of the installer's three
 *     table options that is missing.** {@see SchemaCollation} deliberately offers no
 *     storage engine, and this table is not the exception that should have. InnoDB has
 *     been MySQL's default since 5.5 and MariaDB's since 10.2; a server configured to
 *     default elsewhere is building non-transactional tables for the whole application,
 *     not for the one table that spelled the clause out, and a per-table option cannot
 *     rescue that deployment where a connection preflight could. PostgreSQL and SQLite
 *     have no such concept to restate.
 *   - **`IF NOT EXISTS` is not emitted on any engine.** The compiler has no portable
 *     spelling for it, so the re-runnability it bought is bought here by the
 *     `tableExists()` guard instead — see the next section, which is where that choice
 *     has to be argued rather than merely noted.
 *
 * Three things that look like differences are not. MySQL's `INT` and `INTEGER` are the
 * same type under two spellings, so `attempts` is unchanged there. SQLite gives any
 * declared type containing `INT` integer affinity, so `failed_at` as `BIGINT` stores the
 * same 64-bit signed value its `INTEGER` did. And `DEFAULT CHARSET=utf8mb4` has no
 * separate spelling here because it needs none: MySQL derives a table's character set
 * from its collation when only the collation is given, and `utf8mb4_bin` belongs to
 * exactly one character set, so the `COLLATE=utf8mb4_bin` the compiler emits asks for
 * utf8mb4 in the same breath. That also covers the case the installer's clause was
 * carrying — MySQL 5.7, where `character_set_server` is `latin1` and a non-ASCII queue
 * name would otherwise be rejected.
 *
 * The MySQL primary key is worth one more line, because the reasoning that used to be
 * written here was wrong. `VARCHAR(255)` in utf8mb4 is 1020 bytes, which fits InnoDB's
 * 3072-byte index-key limit for a DYNAMIC or COMPRESSED row and does *not* fit the 767
 * bytes a COMPACT or REDUNDANT row allows. The row format comes from
 * `innodb_default_row_format` — DYNAMIC since MySQL 5.7.9 — or from an explicit
 * `ROW_FORMAT` clause. It does not come from `ENGINE=InnoDB`, so emitting that clause
 * never bought the headroom the earlier note credited it with. On a server configured to
 * COMPACT the create fails with error 1071 at migrate time, which is the loud half of
 * being wrong and is survivable.
 *
 * ## Idempotence, and why nothing returns early
 *
 * `up()` can be entered twice against the same database, on every engine — but not for
 * the reason an earlier draft of this file gave. That draft said MySQL *and SQLite*
 * commit each DDL statement as it runs, and cited
 * {@see SchemaCapabilities::supportsTransactionalDdl()}, which answered `false` for
 * SQLite and was itself the mistake. Executed rather than reasoned about, SQLite
 * contradicted its own capability entry: a `CREATE TABLE`, a `CREATE INDEX`, an
 * `ALTER TABLE ... ADD COLUMN` and a `DROP TABLE` issued after `BEGIN` are all gone again
 * after `ROLLBACK`, and none of them implicitly commits the rows written earlier in the
 * same transaction. SQLite keeps its schema in an ordinary table and journals it with
 * everything else. The capability now says so, and
 * {@see \Pulsar\Tests\Contract\TransactionalDdlContractTest} performs that experiment on
 * every configured engine, so the entry can no longer drift back to a value nobody ran.
 * Nothing below rests on it either way.
 *
 * What does hold, and holds everywhere: {@see \Pulsar\Database\Migration\MigrationRunner}
 * wraps `up()` in a transaction where one is not already open, and writes the
 * applied-migrations row *after* that transaction has committed. A process killed in
 * that window — an OOM, a deploy timeout, a drained node — leaves the table committed
 * and the migration unrecorded, so the next run calls `up()` again over a database that
 * already has this table. PostgreSQL and SQLite reach that state exactly as MySQL does.
 *
 * MySQL adds a second and narrower way in. There DDL genuinely does commit as it runs,
 * so the runner's transaction does not hold `up()` together, and an interruption between
 * the create and the index leaves the table committed *without* its index.
 *
 * Between them that is why there is no `if (tableExists()) { return; }` at the head of
 * `up()`. It reads as an optimisation and behaves as a defect: on MySQL's second run it
 * would return successfully over a table that never got its index, and the runner would
 * write down a success. Each step is guarded by its own postcondition instead — "does
 * the table exist" gates the create, `ensure()`'s catalogue lookup gates the index —
 * exactly as the argument in 20260805000001 sets out. Neither guard reads a predicate
 * the other invalidates, so re-entry converges on the same schema whichever engine
 * allowed it.
 *
 * The create needs that guard because {@see DdlCompiler::compileCreate()} emits a plain
 * `CREATE TABLE`: `IF NOT EXISTS` is not portable through the compiler, and every host
 * that ran the installer already has this table. DDL that failed on an existing table
 * would leave those hosts permanently unable to migrate past this version. The definition
 * deliberately carries no index, so `compileCreate()` returns exactly one statement and
 * the create cannot be interrupted halfway on MySQL, the one engine here that would let
 * a half-finished sequence of DDL stand.
 *
 * The index arrives in three historical shapes, all carrying the name
 * `failed_jobs_failed_at_idx` on the single column `failed_at`: PostgreSQL and SQLite got
 * `CREATE INDEX IF NOT EXISTS`, MySQL — which rejects that clause with error 1064 rather
 * than ignoring it — got `INDEX failed_jobs_failed_at_idx (failed_at)` inline in the
 * `CREATE TABLE`, and the last installer created it through {@see IndexOperations}.
 * `ensure()` decides existence by name, and here that is exact rather than merely
 * adequate: no host can hold an index of that name over different columns, because no
 * version of this schema ever wrote one. It is deliberately not dropped and recreated to
 * "normalise" it — that would rebuild a large index for no schema difference and leave a
 * window with no index at all.
 */
return new class implements MigrationInterface {
    private const string TABLE = 'failed_jobs';

    /**
     * Table-prefixed, and it must stay that way. PostgreSQL and SQLite keep index names in
     * a schema-global namespace where MySQL scopes them per table, so the prefix is what
     * keeps this from colliding with the framework's other single-column time indexes. It
     * is also the literal every past installer produced, which is the name the index
     * already carries on every host that ran one.
     */
    private const string INDEX = 'failed_jobs_failed_at_idx';

    public function up(ConnectionInterface $connection): void
    {
        if (!$this->tableExists($connection)) {
            foreach ($this->compiler($connection)->compileCreate($this->definition()) as $sql) {
                $connection->execute($sql);
            }
        }

        // `all()` orders by `failed_at`, and retention pruning selects on it. One index,
        // identical on every engine, created through the helper for the reason given in
        // the class docblock.
        new IndexOperations($connection)->ensure(self::TABLE, self::INDEX, ['failed_at']);
    }

    /**
     * Reverse the create — but only where there is a create to reverse.
     *
     * On a host that ran `installSchema()` the table pre-existed this migration, `up()`
     * created nothing, and dropping the table would not be a reversal. It would be the
     * destruction of the exact records the table exists to keep, performed by a command
     * an operator reaches for expecting the opposite. So the drop is conditional on the
     * table being empty, which is the only state in which "put it back the way it was"
     * and "delete the dead-letter history" are the same action. A fresh install — the
     * only case where rolling back past this version is meaningful at all — reverses
     * cleanly, and a populated one stops with the count in the message.
     *
     * The count is a guard, not a proof. `pulsar migrate` holds an advisory lock against
     * other migration runs, not against application traffic, so a worker can dead-letter
     * a job between the count and the drop. Stop the workers before rolling back.
     *
     * A table that is not there at all is left alone rather than treated as an error:
     * reversing a create that never happened is a no-op, and saying so is honest.
     *
     * There is no separate `DROP INDEX`, and adding one would be wrong in both orders.
     * After the table is gone the index is too, so the statement fails on every engine
     * looking for an object that no longer exists; before it, the drop is real work that
     * the `DROP TABLE` a line later undoes anyway.
     */
    public function down(ConnectionInterface $connection): void
    {
        if (!$this->tableExists($connection)) {
            return;
        }

        $rows = $this->rowCount($connection);

        if ($rows > 0) {
            throw new RuntimeException(
                'Refusing to drop ' . self::TABLE . ': it holds ' . $rows . ' dead-lettered '
                . 'job record(s), and this migration did not create them — on a host that ran '
                . 'the old DatabaseFailedJobRepository installer the table pre-dated this '
                . 'version, so up() created nothing and dropping it would destroy audit '
                . 'records rather than reverse a change. Export the rows, or purge them '
                . 'through the dead-letter admin action, then roll back again.',
            );
        }

        foreach ($this->compiler($connection)->compileDropTable(self::TABLE) as $sql) {
            $connection->execute($sql);
        }
    }

    /**
     * The table, stated once.
     *
     * Built as a {@see TableDefinition} rather than through {@see \Pulsar\Database\Schema\Blueprint},
     * which cannot express this table: its `primaryKey` flag is set only by `Blueprint::id()`,
     * and that method hands back an auto-incrementing `BIGINT`. `failed_jobs` has a string
     * primary key supplied by the application — copied from the job record, never
     * generated by the database. Nothing here is `SERIAL`, `AUTO_INCREMENT`, `IDENTITY` or
     * SQLite's `INTEGER PRIMARY KEY`, the last of which silently aliases `rowid` and would
     * change what `store()`'s upsert does.
     *
     * `payload` and `exception` are {@see SchemaColumnType::BigText}, which is what the
     * installer's `LONGTEXT` was: 4 GiB on MySQL against the 65,535 *bytes* that plain
     * `Text` compiles to there. The difference is not academic for this table. A
     * serialized-then-encrypted job payload or a deep stack trace passes 64 KiB without
     * being remarkable, and on MySQL under the shipped `sql_mode` an oversize write
     * raises error 1406 and the dead-letter record is lost — the one record whose entire
     * purpose is to outlive the failure that produced it. With strict mode switched off
     * it is truncated instead, which is worse, because nothing says so. PostgreSQL and
     * SQLite compile both cases to `TEXT` and were never at risk.
     *
     * Both are deliberately not JSON columns: after the queue's payload-encryption
     * middleware the payload is ciphertext, not JSON, and a `JSON`/`JSONB` column would
     * reject it at INSERT — turning every encrypted-payload failure into a second
     * failure with nowhere to land. `Binary` is no substitute either, since MySQL's
     * `BLOB` stops at the same 64 KiB `TEXT` does.
     *
     * The table carries {@see SchemaCollation::Exact}, which is the installer's
     * `COLLATE=utf8mb4_bin` and the reason that clause was there. `store()` upserts on
     * the `id` primary key, and under MySQL 8's default `utf8mb4_0900_ai_ci` two ids
     * differing only in case or in accent compare equal — so two distinct dead-lettered
     * jobs collapse onto one row and an audit record disappears into an
     * `ON DUPLICATE KEY UPDATE`. The framework's own drivers cannot reach that, minting
     * lowercase hex ids that cannot differ only in case, but a queue driver supplying its
     * own ids is not bound by that and the guarantee belongs in the schema rather than in
     * an id generator. Stating it once on the table is enough: MySQL applies a table's
     * collation to every character column that does not override it, so `id`, `queue` and
     * `job_class` are all covered without a per-column clause. PostgreSQL and SQLite
     * compare text byte-wise already, and the compiler emits nothing for them.
     *
     * No index is declared here on purpose; see the class docblock.
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
                    length: 255,
                ),
                new SchemaColumn(name: 'queue', type: SchemaColumnType::String, length: 255),
                new SchemaColumn(name: 'job_class', type: SchemaColumnType::String, length: 255),
                new SchemaColumn(name: 'payload', type: SchemaColumnType::BigText),
                new SchemaColumn(name: 'exception', type: SchemaColumnType::BigText),
                new SchemaColumn(name: 'failed_at', type: SchemaColumnType::BigInt),
                new SchemaColumn(name: 'attempts', type: SchemaColumnType::Integer),
            ],
            collation: SchemaCollation::Exact,
        );
    }

    /**
     * The compiler is handed the connection's own driver and variant rather than a guess.
     *
     * The variant matters even though this table declares nothing exotic: MariaDB and
     * MySQL share a driver but not a version line, and the capabilities the compiler
     * consults answer differently for the two.
     */
    private function compiler(ConnectionInterface $connection): DdlCompiler
    {
        return new DdlCompiler(
            $connection->driver(),
            new SchemaCapabilities($connection->driver(), $connection, $connection->variant()),
        );
    }

    /**
     * Asked through {@see TableIntrospector}, which knows the catalogue each engine
     * answers from and the scoping each one needs so the answer describes the table this
     * migration will touch and not a same-named one elsewhere on the search path.
     */
    private function tableExists(ConnectionInterface $connection): bool
    {
        return new TableIntrospector($connection)->tableExists(self::TABLE);
    }

    /**
     * The identifier is delimited through {@see SqlIdentifier}, which validates rather
     * than escapes and picks the delimiter the engine expects — backticks on MySQL, double
     * quotes elsewhere, where a portable spelling does not exist.
     */
    private function rowCount(ConnectionInterface $connection): int
    {
        $row = $connection->query(
            'SELECT COUNT(*) AS c FROM ' . SqlIdentifier::quote(self::TABLE, $connection->driver()),
        )->first();

        return $row?->getInt('c') ?? 0;
    }
};
