<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Executes and previews schema DDL operations.
 *
 * Wraps DdlCompiler with connection execution. Uses transactions
 * for PostgreSQL (which supports transactional DDL). MySQL/MariaDB
 * and SQLite execute statements sequentially without atomicity guarantees.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SchemaManager
{
    public function __construct(
        private ConnectionInterface $connection,
        private DdlCompiler $compiler,
        private SchemaCapabilities $capabilities,
    ) {}

    public function createTable(TableDefinition $definition): void
    {
        $this->executeStatements($this->compiler->compileCreate($definition));
    }

    public function addColumn(string $table, SchemaColumn $column): void
    {
        $this->executeStatements($this->compiler->compileAlterAddColumn($table, $column));
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->executeStatements($this->compiler->compileAlterDropColumn($table, $column));
    }

    public function addIndex(string $table, SchemaIndex $index): void
    {
        $this->executeStatements($this->compiler->compileAddIndex($table, $index));
    }

    /**
     * Scoped to the named table, which the statement itself cannot express.
     *
     * `DROP INDEX` takes only an index name on PostgreSQL and SQLite — the table has
     * nowhere to go in the syntax — while index names are unique per schema and per
     * database respectively, not per table. Dropping by name alone therefore destroys a
     * same-named index belonging to some other table, and `IF EXISTS` turns the miss into
     * silence. {@see IndexOperations} establishes the index belongs to this table before
     * issuing anything, which is where the guarantee has to live.
     *
     * @return bool Whether an index was actually dropped. An index that belonged to
     *              another table, or to nothing, leaves the schema unchanged — and a
     *              caller that records an audit entry needs to know which happened.
     */
    #[NoDiscard]
    public function dropIndex(string $table, string $indexName): bool
    {
        return new IndexOperations($this->connection)->dropIfPresent($table, $indexName);
    }

    public function dropTable(string $table): void
    {
        $this->executeStatements($this->compiler->compileDropTable($table));
    }

    public function renameTable(string $from, string $to): void
    {
        $this->executeStatements($this->compiler->compileRenameTable($from, $to));
    }

    /**
     * @return list<string>
     */
    public function previewCreateTable(TableDefinition $definition): array
    {
        return $this->compiler->compileCreate($definition);
    }

    /**
     * @return list<string>
     */
    public function previewAddColumn(string $table, SchemaColumn $column): array
    {
        return $this->compiler->compileAlterAddColumn($table, $column);
    }

    /**
     * @return list<string>
     */
    public function previewDropColumn(string $table, string $column): array
    {
        return $this->compiler->compileAlterDropColumn($table, $column);
    }

    /**
     * @return list<string>
     */
    public function previewAddIndex(string $table, SchemaIndex $index): array
    {
        return $this->compiler->compileAddIndex($table, $index);
    }

    /**
     * @return list<string>
     */
    public function previewDropIndex(string $table, string $indexName): array
    {
        return $this->compiler->compileDropIndex($table, $indexName);
    }

    /**
     * @return list<string>
     */
    public function previewDropTable(string $table): array
    {
        return $this->compiler->compileDropTable($table);
    }

    /**
     * @return list<string>
     */
    public function previewRenameTable(string $from, string $to): array
    {
        return $this->compiler->compileRenameTable($from, $to);
    }

    public function capabilities(): SchemaCapabilities
    {
        return $this->capabilities;
    }

    /**
     * @param list<string> $statements
     */
    private function executeStatements(array $statements): void
    {
        if ($this->capabilities->supportsTransactionalDdl()) {
            $this->connection->transaction(function (ConnectionInterface $conn) use ($statements): void {
                foreach ($statements as $sql) {
                    $conn->execute($sql);
                }
            });

            return;
        }

        foreach ($statements as $sql) {
            $this->connection->execute($sql);
        }
    }
}
