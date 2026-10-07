<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Create and drop indexes without writing engine-specific SQL.
 *
 * A migration only ever wants two things from an index — that it be there afterwards, or
 * that it be gone — and neither is expressible in one portable statement. MySQL rejects
 * `CREATE INDEX IF NOT EXISTS` as a syntax error rather than degrading, refuses
 * `DROP INDEX IF EXISTS` likewise, and names the owning table on the drop that MariaDB,
 * PostgreSQL and SQLite do not. Writing the standard spelling by hand therefore fails on
 * MySQL for a reason that has nothing to do with the index.
 *
 * Both operations are re-runnable on every supported engine, which matters more than it
 * sounds: {@see \Pulsar\Database\Migration\MigrationRunner} records a migration only once
 * `up()` has returned, so one that dies partway is never marked applied and runs again
 * from the top. A step that is not idempotent turns that second run into a different
 * failure, and the schema stops halfway somewhere new each time.
 *
 * Migrations receive a connection and nothing else, so this takes one and asks it for its
 * dialect rather than being handed collaborators it has no way to obtain.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IndexOperations
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Whether the named index exists on the named table.
     */
    #[NoDiscard]
    public function exists(string $table, string $name): bool
    {
        $result = $this->connection->query(
            $this->connection->dialect()->compileIndexExists(),
            ['table' => $table, 'index' => $name],
        );

        foreach ($result->rows as $row) {
            return $row->getInt('c') > 0;
        }

        return false;
    }

    /**
     * Ensure the index exists, creating it only if it does not.
     *
     * Existence is established by query on every engine rather than delegated to
     * `IF NOT EXISTS` where that clause happens to be available. The clause would spare
     * one round trip on three engines out of four and leave the fourth taking a different
     * path — and a path that only MySQL takes is a path only MySQL can break.
     *
     * `$where` narrows the index to the rows the predicate admits, where the engine has
     * partial indexes. Where it does not, the index covers every row — wider than asked
     * for, and never a wrong answer. Existence is decided by name either way, so changing
     * the predicate of an index that already exists does not rebuild it.
     *
     * A column may be a name, or an {@see IndexColumn} when the order the rows are stored
     * in is the point — a listing that reads "newest first" scans a descending index
     * forwards and stops early, where an ascending one is scanned backwards and cannot.
     *
     * @param list<string|IndexColumn> $columns
     */
    public function ensure(
        string $table,
        string $name,
        array $columns,
        bool $unique = false,
        ?string $where = null,
    ): void {
        if ($this->exists($table, $name)) {
            return;
        }

        $this->connection->execute(
            $this->connection->dialect()->compileCreateIndex(
                $name,
                $table,
                $columns,
                $unique,
                false,
                $where,
            ),
        );
    }

    /**
     * Ensure the index is gone, whether or not it was there.
     *
     * The counterpart to {@see ensure()}, and the one to reach for when the outcome
     * carries no information: a migration putting a schema back the way it found it wants
     * absence, not a report. {@see dropIfPresent()} answers the other question — whether
     * anything was actually dropped — and marks that answer `#[NoDiscard]` because a
     * caller that asks it and ignores it is the shape of an admin action reporting
     * success over a change that never happened.
     */
    public function ensureAbsent(string $table, string $name): void
    {
        if ($this->exists($table, $name)) {
            $this->drop($table, $name);
        }
    }

    /**
     * Drop the index if it is there, and do nothing if it is not.
     *
     * Returns whether anything was dropped. A `void` return made "dropped" and "was never
     * there" indistinguishable to every caller — which is how an admin action can report
     * success, write an audit record with an evidence hash, and change nothing. The
     * caller decides whether absence is acceptable; this only reports it.
     */
    #[NoDiscard]
    public function dropIfPresent(string $table, string $name): bool
    {
        if (!$this->exists($table, $name)) {
            return false;
        }

        $this->drop($table, $name);

        return true;
    }

    private function drop(string $table, string $name): void
    {
        $this->connection->execute(
            $this->connection->dialect()->compileDropIndex($name, $table),
        );
    }
}
