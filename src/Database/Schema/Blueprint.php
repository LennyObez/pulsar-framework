<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

use function is_array;
use function sprintf;

/**
 * Fluent table definition builder for migrations.
 *
 * Collects column, index, and foreign key definitions via a chainable
 * API and compiles them into a {@see TableDefinition}.
 *
 * Usage inside a migration:
 * ```php
 * Schema::create($connection, 'users', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('name');
 *     $table->string('email')->unique();
 *     $table->timestamps();
 * });
 * ```
 */
#[Api(since: '1.0.0')]
final class Blueprint
{
    /** @var list<SchemaColumn> */
    private array $columns = [];

    /** @var list<SchemaIndex> */
    private array $indexes = [];

    /** @var list<SchemaForeignKey> */
    private array $foreignKeys = [];

    private ?ColumnBuilder $pendingColumn = null;

    public function __construct(
        public readonly string $table,
    ) {}

    /**
     * Add an auto-incrementing BIGINT primary key column named "id".
     */
    public function id(string $column = 'id'): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::BigInt, primaryKey: true, autoIncrement: true);
    }

    /**
     * Add a VARCHAR column.
     */
    public function string(string $column, int $length = 255): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::String, length: $length);
    }

    /**
     * Add a TEXT column.
     */
    public function text(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Text);
    }

    /**
     * Add an INTEGER column.
     */
    public function integer(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Integer);
    }

    /**
     * Add a BIGINT column.
     */
    public function bigInteger(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::BigInt);
    }

    /**
     * Add a SMALLINT column.
     */
    public function smallInteger(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::SmallInt);
    }

    /**
     * Add a BOOLEAN column.
     */
    public function boolean(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Boolean);
    }

    /**
     * Add a DATETIME/TIMESTAMP column.
     */
    public function timestamp(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::DateTime);
    }

    /**
     * Add created_at and updated_at DATETIME columns (both nullable).
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable()->default(null)
            ->defaultExpression(SchemaDefaultExpression::CurrentTimestamp);
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * Add a DATE column.
     */
    public function date(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Date);
    }

    /**
     * Add a TIME column.
     */
    public function time(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Time);
    }

    /**
     * Add a FLOAT/DOUBLE PRECISION column.
     */
    public function float(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Float);
    }

    /**
     * Add a DECIMAL column.
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Decimal, precision: $precision, scale: $scale);
    }

    /**
     * Add a JSON/JSONB column.
     */
    public function json(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Json);
    }

    /**
     * Add a UUID column (VARCHAR(36) on MySQL/SQLite, native UUID on PostgreSQL).
     */
    public function uuid(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Uuid);
    }

    /**
     * Add a BINARY/BLOB/BYTEA column.
     */
    public function binary(string $column): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Binary);
    }

    /**
     * Add a BIGINT column intended as a foreign key reference (unsigned on MySQL).
     *
     * Call `->references('id')->on('other_table')` to add the FK constraint.
     */
    public function foreignId(string $column): ForeignIdBuilder
    {
        $colBuilder = $this->addColumn($column, SchemaColumnType::BigInt, unsigned: true);

        return new ForeignIdBuilder($this, $colBuilder, $column);
    }

    /**
     * Add an ENUM column.
     *
     * @param list<string> $values
     */
    public function enum(string $column, array $values): ColumnBuilder
    {
        return $this->addColumn($column, SchemaColumnType::Enum, enumValues: $values);
    }

    /**
     * Add a named index on one or more columns.
     *
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $name ??= sprintf('idx_%s_%s', $this->table, implode('_', $cols));

        $this->indexes[] = new SchemaIndex(name: $name, columns: $cols);
    }

    /**
     * Add a unique index on one or more columns.
     *
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $name ??= sprintf('uq_%s_%s', $this->table, implode('_', $cols));

        $this->indexes[] = new SchemaIndex(name: $name, columns: $cols, unique: true);
    }

    /**
     * Add a foreign key constraint.
     *
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function foreign(
        string $name,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        SchemaReferentialAction $onDelete = SchemaReferentialAction::Restrict,
        SchemaReferentialAction $onUpdate = SchemaReferentialAction::Restrict,
    ): void {
        $this->foreignKeys[] = new SchemaForeignKey(
            name: $name,
            columns: $columns,
            referencedTable: $referencedTable,
            referencedColumns: $referencedColumns,
            onDelete: $onDelete,
            onUpdate: $onUpdate,
        );
    }

    /**
     * Compile collected definitions into a TableDefinition.
     */
    public function toDefinition(): TableDefinition
    {
        $this->flushPendingColumn();

        return new TableDefinition(
            name: $this->table,
            columns: $this->columns,
            indexes: $this->indexes,
            foreignKeys: $this->foreignKeys,
        );
    }

    /**
     * Add a column and return a builder for chaining nullable/default/unique modifiers.
     *
     * @param list<string> $enumValues
     */
    private function addColumn(
        string $name,
        SchemaColumnType $type,
        bool $primaryKey = false,
        bool $autoIncrement = false,
        bool $unsigned = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        array $enumValues = [],
    ): ColumnBuilder {
        $this->flushPendingColumn();

        $builder = new ColumnBuilder(
            name: $name,
            type: $type,
            primaryKey: $primaryKey,
            autoIncrement: $autoIncrement,
            unsigned: $unsigned,
            length: $length,
            precision: $precision,
            scale: $scale,
            enumValues: $enumValues,
        );

        $this->pendingColumn = $builder;

        return $builder;
    }

    /**
     * Flush the pending column builder into the columns list.
     */
    public function flushPendingColumn(): void
    {
        if ($this->pendingColumn !== null) {
            $this->columns[] = $this->pendingColumn->build();
            $this->pendingColumn = null;
        }
    }
}
