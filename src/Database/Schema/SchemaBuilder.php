<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;

/**
 * High-level schema builder for migrations.
 *
 * Provides a fluent, driver-agnostic API for creating, modifying,
 * and dropping database tables. Compiles {@see Blueprint} definitions
 * into driver-specific DDL via the existing {@see DdlCompiler}.
 *
 * Usage:
 * ```php
 * $schema = SchemaBuilder::for($connection);
 *
 * $schema->create('users', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('email', 191)->unique();
 *     $table->timestamps();
 * });
 *
 * $schema->drop('users');
 * ```
 */
#[Api(since: '1.0.0')]
final readonly class SchemaBuilder
{
    private DdlCompiler $compiler;
    private SchemaCapabilities $capabilities;

    public function __construct(
        private ConnectionInterface $connection,
    ) {
        $driver = $connection->driver();
        $this->capabilities = new SchemaCapabilities($driver, $connection);
        $this->compiler = new DdlCompiler($driver, $this->capabilities);
    }

    /**
     * Create a SchemaBuilder for the given connection.
     */
    public static function for(ConnectionInterface $connection): self
    {
        return new self($connection);
    }

    /**
     * Create a new table.
     *
     * @param Closure(Blueprint): void $callback
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $definition = $blueprint->toDefinition();

        $this->executeStatements($this->compiler->compileCreate($definition));
    }

    /**
     * Modify an existing table (add columns, indexes, foreign keys).
     *
     * @param Closure(Blueprint): void $callback
     */
    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $definition = $blueprint->toDefinition();

        foreach ($definition->columns as $column) {
            $this->executeStatements(
                $this->compiler->compileAlterAddColumn($table, $column),
            );
        }

        foreach ($definition->indexes as $index) {
            $this->executeStatements(
                $this->compiler->compileAddIndex($table, $index),
            );
        }
    }

    /**
     * Check whether a table exists in the database.
     */
    public function hasTable(string $table): bool
    {
        $driver = $this->connection->driver();

        $sql = match ($driver) {
            Driver::SQLite => "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type = 'table' AND name = :table",
            Driver::MySQL => 'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            Driver::PostgreSQL => "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table",
        };

        $result = $this->connection->query($sql, ['table' => $table]);

        if ($result->rows === []) {
            return false;
        }

        return $result->rows[0]->getInt('cnt') > 0;
    }

    /**
     * Drop a table (IF EXISTS).
     */
    public function drop(string $table): void
    {
        $this->executeStatements($this->compiler->compileDropTable($table));
    }

    /**
     * Drop a table if it exists (alias for drop; uses IF EXISTS internally).
     */
    public function dropIfExists(string $table): void
    {
        $this->drop($table);
    }

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void
    {
        $this->executeStatements($this->compiler->compileRenameTable($from, $to));
    }

    /**
     * Drop a column from a table.
     *
     * @throws SchemaException On unsupported drivers (older SQLite)
     */
    public function dropColumn(string $table, string $column): void
    {
        $this->executeStatements($this->compiler->compileAlterDropColumn($table, $column));
    }

    /**
     * Preview the DDL statements that would be generated for a table creation.
     *
     * @param Closure(Blueprint): void $callback
     * @return list<string>
     */
    public function preview(string $table, Closure $callback): array
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $definition = $blueprint->toDefinition();

        return $this->compiler->compileCreate($definition);
    }

    /**
     * Get the underlying connection.
     */
    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Get driver capabilities.
     */
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
