<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\ColumnType;
use Pulsar\Extension\Orm\Internal\Compiler\DialectInterface;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

use function implode;
use function sprintf;

/**
 * Compiles DDL statements from TableBuilder definitions.
 */
#[Internal]
final class SchemaDdlCompiler
{
    public function __construct(
        private readonly IdentifierQuoter $quoter,
        private readonly DialectInterface $dialect,
    ) {}

    /**
     * Compile a CREATE TABLE statement.
     */
    public function compileCreate(TableBuilder $builder): string
    {
        $parts = [];

        foreach ($builder->getColumns() as $col) {
            $parts[] = $this->compileColumnDef($col);
        }

        if ($builder->getPrimaryKeys() !== []) {
            $pks = \array_map(fn(string $c): string => $this->quoter->quote($c), $builder->getPrimaryKeys());
            $parts[] = sprintf('PRIMARY KEY (%s)', implode(', ', $pks));
        }

        foreach ($builder->getIndexes() as $index) {
            if ($index->unique) {
                $cols = \array_map(fn(string $c): string => $this->quoter->quote($c), $index->columns);
                $parts[] = sprintf(
                    'CONSTRAINT %s UNIQUE (%s)',
                    $this->quoter->quote($index->name),
                    implode(', ', $cols),
                );
            }
        }

        foreach ($builder->getForeignKeys() as $fk) {
            $parts[] = $this->compileForeignKey($fk);
        }

        $sql = sprintf(
            'CREATE TABLE %s (%s)',
            $this->quoter->quote($builder->tableName),
            implode(', ', $parts),
        );

        // Non-unique indexes as separate statements
        $statements = [$sql];
        foreach ($builder->getIndexes() as $index) {
            if (!$index->unique) {
                $cols = \array_map(fn(string $c): string => $this->quoter->quote($c), $index->columns);
                $statements[] = sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->quoter->quote($index->name),
                    $this->quoter->quote($builder->tableName),
                    implode(', ', $cols),
                );
            }
        }

        return implode(";\n", $statements);
    }

    /**
     * Compile ALTER TABLE statements for modifications.
     *
     * @return list<string>
     */
    public function compileAlter(TableBuilder $builder): array
    {
        $statements = [];

        foreach ($builder->getDropIndexes() as $index) {
            $statements[] = sprintf(
                'DROP INDEX %s',
                $this->quoter->quote($index),
            );
        }

        foreach ($builder->getDropColumns() as $col) {
            $statements[] = sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoter->quote($builder->tableName),
                $this->quoter->quote($col),
            );
        }

        foreach ($builder->getColumns() as $col) {
            $statements[] = sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $this->quoter->quote($builder->tableName),
                $this->compileColumnDef($col),
            );
        }

        foreach ($builder->getIndexes() as $index) {
            $cols = \array_map(fn(string $c): string => $this->quoter->quote($c), $index->columns);
            $keyword = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
            $statements[] = sprintf(
                'CREATE %s %s ON %s (%s)',
                $keyword,
                $this->quoter->quote($index->name),
                $this->quoter->quote($builder->tableName),
                implode(', ', $cols),
            );
        }

        foreach ($builder->getForeignKeys() as $fk) {
            $statements[] = sprintf(
                'ALTER TABLE %s ADD %s',
                $this->quoter->quote($builder->tableName),
                $this->compileForeignKey($fk),
            );
        }

        return $statements;
    }

    private function compileColumnDef(ColumnDefinition $col): string
    {
        $sql = $this->quoter->quote($col->name) . ' ' . $this->mapType($col);

        if ($col->isUnsigned()) {
            $sql .= ' UNSIGNED';
        }

        if (!$col->isNullable()) {
            $sql .= ' NOT NULL';
        }

        if ($col->isAutoIncrement()) {
            $sql .= match ($this->dialect->name()) {
                'pgsql' => '', // BIGSERIAL handles this
                'sqlite' => ' AUTOINCREMENT',
                default => ' AUTO_INCREMENT',
            };
        }

        if ($col->hasDefaultValue()) {
            $default = $col->getDefault();
            if ($default === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (\is_bool($default)) {
                $sql .= ' DEFAULT ' . $this->dialect->compileBooleanLiteral($default);
            } elseif (\is_int($default) || \is_float($default)) {
                $sql .= sprintf(' DEFAULT %s', (string) $default);
            } else {
                $sql .= sprintf(" DEFAULT '%s'", (string) $default);
            }
        }

        if ($col->isUnique() && !$col->isPrimaryKey()) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private function mapType(ColumnDefinition $col): string
    {
        $dialectName = $this->dialect->name();

        return match ($col->type) {
            ColumnType::String => sprintf('VARCHAR(%d)', $col->getLength() ?? 255),
            ColumnType::Text => 'TEXT',
            ColumnType::Integer => 'INTEGER',
            ColumnType::SmallInt => 'SMALLINT',
            ColumnType::BigInt => match ($dialectName) {
                'pgsql' => $col->isAutoIncrement() ? 'BIGSERIAL' : 'BIGINT',
                default => 'BIGINT',
            },
            ColumnType::Float => match ($dialectName) {
                'pgsql' => 'DOUBLE PRECISION',
                default => 'FLOAT',
            },
            ColumnType::Decimal => sprintf('DECIMAL(%d, %d)', $col->getPrecision() ?? 8, $col->getScale() ?? 2),
            ColumnType::Boolean => match ($dialectName) {
                'pgsql' => 'BOOLEAN',
                default => 'TINYINT(1)',
            },
            ColumnType::DateTime => match ($dialectName) {
                'pgsql' => 'TIMESTAMP',
                default => 'DATETIME',
            },
            ColumnType::Date => 'DATE',
            ColumnType::Time => 'TIME',
            ColumnType::Json => match ($dialectName) {
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                default => 'JSON',
            },
            ColumnType::Uuid => match ($dialectName) {
                'pgsql' => 'UUID',
                default => sprintf('VARCHAR(%d)', $col->getLength() ?? 36),
            },
            ColumnType::Binary => match ($dialectName) {
                'pgsql' => 'BYTEA',
                default => 'BLOB',
            },
            ColumnType::Enum => sprintf('VARCHAR(%d)', $col->getLength() ?? 50),
        };
    }

    private function compileForeignKey(ForeignKeyDefinition $fk): string
    {
        $localCols = \array_map(fn(string $c): string => $this->quoter->quote($c), $fk->columns);
        $refCols = \array_map(fn(string $c): string => $this->quoter->quote($c), $fk->referencedColumns);

        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $this->quoter->quote($fk->name),
            implode(', ', $localCols),
            $this->quoter->quote($fk->referencedTable),
            implode(', ', $refCols),
            $fk->onDelete,
            $fk->onUpdate,
        );
    }
}
