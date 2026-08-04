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
     * @param list<string> $columns
     */
    public function ensure(string $table, string $name, array $columns, bool $unique = false): void
    {
        if ($this->exists($table, $name)) {
            return;
        }

        $this->connection->execute(
            $this->connection->dialect()->compileCreateIndex($name, $table, $columns, $unique),
        );
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

        $this->connection->execute(
            $this->connection->dialect()->compileDropIndex($name, $table),
        );

        return true;
    }
}
