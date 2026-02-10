<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;
use Pulsar\Database\Driver;

use function array_map;
use function count;
use function implode;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function sprintf;
use function str_starts_with;

/**
 * Compiles DDL statements from schema DTOs.
 *
 * All methods return list<string> to support multi-statement DDL
 * (e.g., CREATE TABLE + separate CREATE INDEX statements).
 *
 * Driver-aware: uses appropriate quoting, type mapping, and syntax
 * for MySQL/MariaDB, PostgreSQL, and SQLite.
 */
#[Api(since: '1.0.0')]
final readonly class DdlCompiler
{
    public function __construct(
        private Driver $driver,
        private SchemaCapabilities $capabilities,
    ) {}

    /**
     * @return list<string>
     */
    public function compileCreate(TableDefinition $def): array
    {
        $parts = [];
        $pkColumns = [];

        foreach ($def->columns as $column) {
            if ($column->primaryKey) {
                $pkColumns[] = $column->name;
            }
        }

        $singlePkAutoIncrement = count($pkColumns) === 1 && $this->hasSingleAutoIncrementPk($def);

        foreach ($def->columns as $column) {
            $parts[] = $this->compileColumnDef($column, $singlePkAutoIncrement);
        }

        // Table-level PRIMARY KEY constraint
        if (count($pkColumns) > 1) {
            $quotedPks = array_map(fn(string $c): string => $this->quoteIdentifier($c), $pkColumns);
            $parts[] = sprintf('PRIMARY KEY (%s)', implode(', ', $quotedPks));
        } elseif (count($pkColumns) === 1 && !$singlePkAutoIncrement) {
            $parts[] = sprintf('PRIMARY KEY (%s)', $this->quoteIdentifier($pkColumns[0]));
        }

        // Unique indexes as inline constraints
        foreach ($def->indexes as $index) {
            if ($index->unique) {
                $cols = array_map(fn(string $c): string => $this->quoteIdentifier($c), $index->columns);
                $parts[] = sprintf(
                    'CONSTRAINT %s UNIQUE (%s)',
                    $this->quoteIdentifier($this->prefixIndexName($def->name, $index->name)),
                    implode(', ', $cols),
                );
            }
        }

        // Foreign keys
        foreach ($def->foreignKeys as $fk) {
            $parts[] = $this->compileForeignKeyConstraint($fk);
        }

        $statements = [];
        $statements[] = sprintf(
            'CREATE TABLE %s (%s)',
            $this->quoteIdentifier($def->name),
            implode(', ', $parts),
        );

        // Non-unique indexes as separate CREATE INDEX statements
        foreach ($def->indexes as $index) {
            if (!$index->unique) {
                $cols = array_map(fn(string $c): string => $this->quoteIdentifier($c), $index->columns);
                $indexName = $this->prefixIndexName($def->name, $index->name);
                $statements[] = sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $this->quoteIdentifier($indexName),
                    $this->quoteIdentifier($def->name),
                    implode(', ', $cols),
                );
            }
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function compileAlterAddColumn(string $table, SchemaColumn $column): array
    {
        return [
            sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $this->quoteIdentifier($table),
                $this->compileColumnDef($column, false),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function compileAlterDropColumn(string $table, string $column): array
    {
        if (!$this->capabilities->supportsDropColumn()) {
            throw SchemaException::operationNotSupported($this->driver, 'DROP COLUMN');
        }

        return [
            sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($column),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function compileAddIndex(string $table, SchemaIndex $index): array
    {
        $cols = array_map(fn(string $c): string => $this->quoteIdentifier($c), $index->columns);
        $indexName = $this->prefixIndexName($table, $index->name);
        $keyword = $index->unique ? 'UNIQUE INDEX' : 'INDEX';

        return [
            sprintf(
                'CREATE %s %s ON %s (%s)',
                $keyword,
                $this->quoteIdentifier($indexName),
                $this->quoteIdentifier($table),
                implode(', ', $cols),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function compileDropIndex(string $table, string $indexName): array
    {
        return match ($this->driver) {
            Driver::MySQL => [
                sprintf(
                    'DROP INDEX IF EXISTS %s ON %s',
                    $this->quoteIdentifier($indexName),
                    $this->quoteIdentifier($table),
                ),
            ],
            Driver::PostgreSQL, Driver::SQLite => [
                sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($indexName)),
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function compileDropTable(string $table): array
    {
        return [sprintf('DROP TABLE IF EXISTS %s', $this->quoteIdentifier($table))];
    }

    /**
     * @return list<string>
     */
    public function compileRenameTable(string $from, string $to): array
    {
        return match ($this->driver) {
            Driver::MySQL => [
                sprintf(
                    'RENAME TABLE %s TO %s',
                    $this->quoteIdentifier($from),
                    $this->quoteIdentifier($to),
                ),
            ],
            Driver::PostgreSQL, Driver::SQLite => [
                sprintf(
                    'ALTER TABLE %s RENAME TO %s',
                    $this->quoteIdentifier($from),
                    $this->quoteIdentifier($to),
                ),
            ],
        };
    }

    private function compileColumnDef(SchemaColumn $column, bool $singlePkAutoIncrement): string
    {
        $type = $this->mapType($column);
        $sql = $this->quoteIdentifier($column->name) . ' ' . $type;

        // UNSIGNED (MySQL/MariaDB only, silently ignored for others)
        if ($column->unsigned && $this->driver === Driver::MySQL) {
            $sql .= ' UNSIGNED';
        }

        // PRIMARY KEY + AUTO_INCREMENT for single-PK-auto-increment scenario
        if ($singlePkAutoIncrement && $column->primaryKey && $column->autoIncrement) {
            if ($this->driver !== Driver::PostgreSQL) {
                // PostgreSQL uses SERIAL/BIGSERIAL which includes PK handling
                $sql .= ' PRIMARY KEY';
            }

            $sql .= match ($this->driver) {
                Driver::MySQL => ' AUTO_INCREMENT',
                Driver::SQLite => ' AUTOINCREMENT',
                Driver::PostgreSQL => ' PRIMARY KEY', // SERIAL already set; just add PK
            };

            return $this->appendDefault($sql, $column);
        }

        // Auto-increment without PK scenario (rare, but handle it)
        if ($column->autoIncrement && !$column->primaryKey) {
            $sql .= match ($this->driver) {
                Driver::MySQL => ' AUTO_INCREMENT',
                Driver::SQLite, Driver::PostgreSQL => '',
            };
        }

        if (!$column->nullable) {
            $sql .= ' NOT NULL';
        }

        $sql = $this->appendDefault($sql, $column);

        if ($column->unique && !$column->primaryKey) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private function appendDefault(string $sql, SchemaColumn $column): string
    {
        if ($column->defaultExpression !== null) {
            $sql .= ' DEFAULT ' . $column->defaultExpression->value;
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->quoteDefaultValue($column->default);
        }

        return $sql;
    }

    private function mapType(SchemaColumn $column): string
    {
        return match ($column->type) {
            SchemaColumnType::String => sprintf('VARCHAR(%d)', $column->length ?? 255),
            SchemaColumnType::Text => 'TEXT',
            SchemaColumnType::Integer => match ($this->driver) {
                Driver::PostgreSQL => $column->autoIncrement ? 'SERIAL' : 'INTEGER',
                default => 'INTEGER',
            },
            SchemaColumnType::SmallInt => 'SMALLINT',
            SchemaColumnType::BigInt => match ($this->driver) {
                Driver::PostgreSQL => $column->autoIncrement ? 'BIGSERIAL' : 'BIGINT',
                default => 'BIGINT',
            },
            SchemaColumnType::Float => match ($this->driver) {
                Driver::PostgreSQL => 'DOUBLE PRECISION',
                default => 'FLOAT',
            },
            SchemaColumnType::Decimal => sprintf('DECIMAL(%d, %d)', $column->precision ?? 8, $column->scale ?? 2),
            SchemaColumnType::Boolean => match ($this->driver) {
                Driver::PostgreSQL => 'BOOLEAN',
                Driver::MySQL => 'TINYINT(1)',
                Driver::SQLite => 'INTEGER',
            },
            SchemaColumnType::DateTime => match ($this->driver) {
                Driver::PostgreSQL => 'TIMESTAMP',
                default => 'DATETIME',
            },
            SchemaColumnType::Date => 'DATE',
            SchemaColumnType::Time => 'TIME',
            SchemaColumnType::Json => match ($this->driver) {
                Driver::PostgreSQL => 'JSONB',
                Driver::SQLite => 'TEXT',
                default => 'JSON',
            },
            SchemaColumnType::Uuid => match ($this->driver) {
                Driver::PostgreSQL => 'UUID',
                default => 'VARCHAR(36)',
            },
            SchemaColumnType::Binary => match ($this->driver) {
                Driver::PostgreSQL => 'BYTEA',
                default => 'BLOB',
            },
            SchemaColumnType::Enum => $this->compileEnumType($column),
        };
    }

    private function compileEnumType(SchemaColumn $column): string
    {
        if ($column->enumValues === []) {
            return sprintf('VARCHAR(%d)', $column->length ?? 50);
        }

        $escaped = array_map(
            static fn(string $v): string => sprintf("'%s'", str_replace("'", "''", $v)),
            $column->enumValues,
        );

        if ($this->driver === Driver::MySQL) {
            return sprintf('ENUM(%s)', implode(', ', $escaped));
        }

        // PostgreSQL + SQLite: CHECK constraint on VARCHAR
        return sprintf(
            'VARCHAR(%d) CHECK (%s IN (%s))',
            $column->length ?? 50,
            $this->quoteIdentifier($column->name),
            implode(', ', $escaped),
        );
    }

    private function compileForeignKeyConstraint(SchemaForeignKey $fk): string
    {
        $localCols = array_map(fn(string $c): string => $this->quoteIdentifier($c), $fk->columns);
        $refCols = array_map(fn(string $c): string => $this->quoteIdentifier($c), $fk->referencedColumns);

        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $this->quoteIdentifier($fk->name),
            implode(', ', $localCols),
            $this->quoteIdentifier($fk->referencedTable),
            implode(', ', $refCols),
            $fk->onDelete->value,
            $fk->onUpdate->value,
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return match ($this->driver) {
            Driver::MySQL => '`' . str_replace('`', '``', $identifier) . '`',
            Driver::PostgreSQL, Driver::SQLite => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    private function quoteDefaultValue(int|float|string|bool|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return match ($this->driver) {
                Driver::PostgreSQL => $value ? 'TRUE' : 'FALSE',
                default => $value ? '1' : '0',
            };
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return sprintf("'%s'", str_replace("'", "''", $value));
    }

    /**
     * Prefix index name with table name for PostgreSQL schema scoping
     * if not already prefixed.
     */
    private function prefixIndexName(string $table, string $indexName): string
    {
        if ($this->driver === Driver::PostgreSQL && !str_starts_with($indexName, $table . '_')) {
            return $table . '_' . $indexName;
        }

        return $indexName;
    }

    private function hasSingleAutoIncrementPk(TableDefinition $def): bool
    {
        $autoIncrementPks = 0;
        foreach ($def->columns as $column) {
            if ($column->primaryKey && $column->autoIncrement) {
                $autoIncrementPks++;
            }
        }

        return $autoIncrementPks === 1 && in_array(true, array_map(
            static fn(SchemaColumn $c): bool => $c->primaryKey,
            $def->columns,
        ), true);
    }
}
