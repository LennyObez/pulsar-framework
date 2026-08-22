<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;
use Pulsar\Database\Dialect\Dialects;
use Pulsar\Database\Driver;
use Pulsar\Database\SqlIdentifier;

use function array_any;
use function array_map;
use function count;
use function implode;
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
 * @api
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
            'CREATE TABLE %s (%s)%s',
            $this->quoteIdentifier($def->name),
            implode(', ', $parts),
            $this->compileTableOptions($def),
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
     * Delegated rather than matched on the driver.
     *
     * The arm this replaced emitted `DROP INDEX IF EXISTS <name> ON <table>` for MySQL,
     * which MySQL rejects outright — error 1064, a parse failure, not a tolerated
     * no-op — so every drop through this compiler failed on that engine. The dialect
     * has spelled it correctly all along; two implementations of one statement is what
     * let them disagree, so now there is one.
     *
     * @return list<string>
     */
    public function compileDropIndex(string $table, string $indexName): array
    {
        return [Dialects::for($this->driver, $this->capabilities->driverVariant())
            ->compileDropIndex($indexName, $table)];
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

    /**
     * Get a driver-appropriate default expression for UUID generation.
     *
     * PostgreSQL: gen_random_uuid()
     * MySQL 8.0+: (UUID())
     * SQLite: no native UUID: returns null (application must provide UUIDs)
     */
    public function uuidDefaultExpression(): ?SchemaDefaultExpression
    {
        return match ($this->driver) {
            Driver::PostgreSQL => SchemaDefaultExpression::PostgresUuid,
            Driver::MySQL => SchemaDefaultExpression::MysqlUuid,
            Driver::SQLite => null,
        };
    }

    private function compileColumnDef(SchemaColumn $column, bool $singlePkAutoIncrement): string
    {
        $type = $this->mapType($column);
        $sql = $this->quoteIdentifier($column->name) . ' ' . $type . $this->compileColumnCollation($column);

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
            // MySQL is the only engine where the width of a text column is a decision.
            // Its TEXT stops at 65,535 bytes; LONGTEXT is the widest of the four and the
            // only one that never has to be revisited. PostgreSQL's and SQLite's TEXT are
            // already as wide as either engine goes, so the narrow and wide cases
            // converge there rather than one of them being approximated.
            SchemaColumnType::BigText => match ($this->driver) {
                Driver::MySQL => 'LONGTEXT',
                Driver::PostgreSQL, Driver::SQLite => 'TEXT',
            },
            SchemaColumnType::Integer => match ($this->driver) {
                Driver::PostgreSQL => $column->autoIncrement ? 'SERIAL' : 'INTEGER',
                default => 'INTEGER',
            },
            SchemaColumnType::SmallInt => 'SMALLINT',
            SchemaColumnType::BigInt => match ($this->driver) {
                Driver::PostgreSQL => $column->autoIncrement ? 'BIGSERIAL' : 'BIGINT',
                // SQLite accepts AUTOINCREMENT only on a column declared exactly
                // `INTEGER PRIMARY KEY`: that spelling, and no other, makes the column an
                // alias of the rowid, and AUTOINCREMENT is a modifier on the rowid
                // counter. `BIGINT PRIMARY KEY AUTOINCREMENT` is rejected outright.
                // Nothing is lost by the substitution — SQLite's INTEGER is already a
                // 64-bit signed value, so the range is the one BigInt asked for.
                Driver::SQLite => $column->autoIncrement && $column->primaryKey
                    ? 'INTEGER'
                    : 'BIGINT',
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
                Driver::MySQL => 'DATETIME(6)',
                Driver::SQLite => 'DATETIME',
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

    /**
     * The clauses that follow a CREATE TABLE column list.
     *
     * Only MySQL has a table-level collation clause, so only MySQL emits anything. The
     * silence on the other two is the correct translation rather than a dropped feature:
     * PostgreSQL and SQLite already compare text exactly by default, which is the only
     * intent {@see SchemaCollation} can express, and neither has syntax here to say so
     * twice. {@see SchemaCollation::Exact} carries the per-engine reasoning.
     */
    private function compileTableOptions(TableDefinition $def): string
    {
        if ($def->collation === null) {
            return '';
        }

        return match ($this->driver) {
            // `COLLATE=x` with the equals sign is the table-option spelling; the column
            // spelling below omits it, and MySQL rejects each in the other's position.
            Driver::MySQL => ' COLLATE=' . $this->collationName($def->collation),
            Driver::PostgreSQL, Driver::SQLite => '',
        };
    }

    /**
     * A collation clause for one column, where the engine accepts one.
     *
     * Guarded on the column type as well as the driver: MySQL refuses `COLLATE` on
     * anything that is not a character type — an `INT` or a `JSON` column with a
     * collation is a parse error, not an ignored hint — so a caller that sets one table
     * wide must not have it land on the integers.
     */
    private function compileColumnCollation(SchemaColumn $column): string
    {
        if ($column->collation === null || !$this->acceptsCollation($column->type)) {
            return '';
        }

        return match ($this->driver) {
            Driver::MySQL => ' COLLATE ' . $this->collationName($column->collation),
            Driver::PostgreSQL, Driver::SQLite => '',
        };
    }

    /**
     * Whether a collation clause is legal on this type.
     *
     * Every case is listed rather than falling through a default, so that adding a column
     * type is a decision made here instead of a silent `false` inherited from a type
     * nobody compared it against.
     */
    private function acceptsCollation(SchemaColumnType $type): bool
    {
        return match ($type) {
            SchemaColumnType::String,
            SchemaColumnType::Text,
            SchemaColumnType::BigText,
            SchemaColumnType::Uuid,
            // ENUM on MySQL, VARCHAR with a CHECK elsewhere: character either way.
            SchemaColumnType::Enum => true,
            // JSON is excluded even though it holds text. MySQL fixes a JSON column at
            // utf8mb4_bin itself and rejects any COLLATE clause on it.
            SchemaColumnType::Json,
            SchemaColumnType::Integer,
            SchemaColumnType::SmallInt,
            SchemaColumnType::BigInt,
            SchemaColumnType::Float,
            SchemaColumnType::Decimal,
            SchemaColumnType::Boolean,
            SchemaColumnType::DateTime,
            SchemaColumnType::Date,
            SchemaColumnType::Time,
            SchemaColumnType::Binary => false,
        };
    }

    /**
     * The engine's name for a comparison rule.
     *
     * Reached only on the MySQL family: the other two return before asking, because they
     * have nothing to spell. `utf8mb4_bin` exists on MySQL and MariaDB alike, so the
     * variant does not change the answer, and naming it here rather than in the enum
     * keeps the engine's vocabulary inside the one class whose job is to know it.
     */
    private function collationName(SchemaCollation $collation): string
    {
        return match ($collation) {
            SchemaCollation::Exact => 'utf8mb4_bin',
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

    /**
     * Delimit an identifier for the target engine.
     *
     * Delegates to {@see SqlIdentifier}, which validates rather than escapes. The two are
     * not equivalent postures: escaping accepts any name and relies on doubling the
     * delimiter correctly, while validation refuses any name that could carry a
     * delimiter, a comment introducer or a statement separator in the first place. The
     * second is the stronger guarantee, and it matters most on the path where a name
     * arrives from a user — the admin schema editor — rather than from a migration
     * written by hand.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return SqlIdentifier::quote($identifier, $this->driver);
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

        return $autoIncrementPks === 1 && array_any(
            $def->columns,
            static fn(SchemaColumn $c): bool => $c->primaryKey,
        );
    }
}
