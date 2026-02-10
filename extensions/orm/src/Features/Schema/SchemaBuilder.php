<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\SchemaBuilderInterface;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function sprintf;

/**
 * Schema management implementation that compiles and executes DDL statements.
 */
#[Internal]
final class SchemaBuilder implements SchemaBuilderInterface
{
    private readonly SchemaDdlCompiler $ddlCompiler;
    private readonly IdentifierQuoter $quoter;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->quoter = new IdentifierQuoter($connection->driver());
        $this->ddlCompiler = new SchemaDdlCompiler($this->quoter, $this->quoter->dialect());
    }

    #[Override]
    public function create(string $table, callable $callback): void
    {
        $builder = new TableBuilder($table);
        $callback($builder);

        $sql = $this->ddlCompiler->compileCreate($builder);
        foreach (\explode(";\n", $sql) as $statement) {
            $statement = \trim($statement);
            if ($statement !== '') {
                $this->connection->execute($statement);
            }
        }
    }

    #[Override]
    public function table(string $table, callable $callback): void
    {
        $builder = new TableBuilder($table);
        $callback($builder);

        $statements = $this->ddlCompiler->compileAlter($builder);
        foreach ($statements as $sql) {
            $this->connection->execute($sql);
        }
    }

    #[Override]
    public function drop(string $table): void
    {
        $this->connection->execute(sprintf('DROP TABLE %s', $this->quoter->quote($table)));
    }

    #[Override]
    public function dropIfExists(string $table): void
    {
        $this->connection->execute(sprintf('DROP TABLE IF EXISTS %s', $this->quoter->quote($table)));
    }

    #[Override]
    public function rename(string $from, string $to): void
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => sprintf('RENAME TABLE %s TO %s', $this->quoter->quote($from), $this->quoter->quote($to)),
            Driver::PostgreSQL, Driver::SQLite => sprintf('ALTER TABLE %s RENAME TO %s', $this->quoter->quote($from), $this->quoter->quote($to)),
        };

        $this->connection->execute($sql);
    }

    #[Override]
    public function hasTable(string $table): bool
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => "SELECT 1 FROM information_schema.tables WHERE table_name = :table AND table_schema = DATABASE() LIMIT 1",
            Driver::PostgreSQL => "SELECT 1 FROM information_schema.tables WHERE table_name = :table AND table_schema = 'public' LIMIT 1",
            Driver::SQLite => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1",
        };

        $result = $this->connection->query($sql, ['table' => $table]);

        return !$result->isEmpty();
    }

    #[Override]
    public function hasColumn(string $table, string $column): bool
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => "SELECT 1 FROM information_schema.columns WHERE table_name = :table AND column_name = :column AND table_schema = DATABASE() LIMIT 1",
            Driver::PostgreSQL => "SELECT 1 FROM information_schema.columns WHERE table_name = :table AND column_name = :column AND table_schema = 'public' LIMIT 1",
            Driver::SQLite => sprintf("PRAGMA table_info(%s)", $this->quoter->quote($table)),
        };

        if ($this->connection->driver() === Driver::SQLite) {
            $result = $this->connection->query($sql);
            foreach ($result->rows as $row) {
                if ($row->getString('name') === $column) {
                    return true;
                }
            }

            return false;
        }

        $result = $this->connection->query($sql, ['table' => $table, 'column' => $column]);

        return !$result->isEmpty();
    }
}
