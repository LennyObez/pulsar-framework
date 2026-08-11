<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Ask what a schema already contains, without writing engine-specific SQL.
 *
 * A migration that is re-runnable has to be able to see which of its steps already
 * happened, and every one of those questions has a different answer per engine: SQLite
 * keeps them in `sqlite_master` and the `pragma_*` table-valued functions, MySQL in
 * `information_schema`, PostgreSQL in the `pg_*` catalogues. Written by hand at the call
 * site, that is three spellings a migration author has to get right for engines they may
 * never run, and one of them silently answers for the wrong table: an
 * `information_schema.columns` lookup with no schema predicate resolves against whatever
 * the search path offers, which on PostgreSQL's stock path is not necessarily the table
 * the DDL will touch.
 *
 * Being able to see the current shape is what makes a step safe to repeat.
 * {@see \Pulsar\Database\Migration\MigrationRunner} records a migration only once `up()`
 * has returned, so one that dies partway is never marked applied and runs again from the
 * top — over a schema it has already half-changed.
 *
 * Each answer is a postcondition in its own right and should be asked as one. Inferring
 * "the key is there" from "the step that adds it ran" is how a run interrupted between
 * two statements resumes, skips the branch, and reports success over a table whose
 * uniqueness nothing enforces.
 *
 * Migrations receive a connection and nothing else, so this takes one and asks it for its
 * dialect rather than being handed collaborators it has no way to obtain.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TableIntrospector
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Whether the named table exists.
     */
    #[NoDiscard]
    public function tableExists(string $table): bool
    {
        return $this->countIsPositive(
            $this->connection->dialect()->compileTableExists(),
            ['table' => $table],
        );
    }

    /**
     * Whether the named column exists on the named table.
     *
     * A table that does not exist reports every column absent rather than raising: the
     * caller that cares about the difference asks {@see tableExists()} first, and the
     * caller that does not would have had to catch an exception to get the same answer.
     */
    #[NoDiscard]
    public function columnExists(string $table, string $column): bool
    {
        return $this->countIsPositive(
            $this->connection->dialect()->compileColumnExists(),
            ['table' => $table, 'column' => $column],
        );
    }

    /**
     * Whether the named table carries a primary key.
     *
     * Not which columns form it: a caller that needs the composition is asking a question
     * about a specific key it expects, which this cannot answer portably — the three
     * catalogues disagree on both the shape and the ordering of that answer.
     */
    #[NoDiscard]
    public function hasPrimaryKey(string $table): bool
    {
        return $this->countIsPositive(
            $this->connection->dialect()->compilePrimaryKeyExists(),
            ['table' => $table],
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countIsPositive(string $sql, array $parameters): bool
    {
        $result = $this->connection->query($sql, $parameters);

        foreach ($result->rows as $row) {
            return $row->getInt('c') > 0;
        }

        return false;
    }
}
