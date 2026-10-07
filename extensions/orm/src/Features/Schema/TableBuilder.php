<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\ColumnType;

use function sprintf;

/**
 * Fluent table definition builder for schema operations.
 * @api
 */
#[Api(since: '1.0.0')]
final class TableBuilder
{
    /** @var list<ColumnDefinition> */
    public private(set) array $columns = [];

    /** @var list<IndexDefinition> */
    public private(set) array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    public private(set) array $foreignKeys = [];

    /** @var list<string> */
    public private(set) array $primaryKeys = [];

    /** @var list<string> */
    public private(set) array $dropColumns = [];

    /** @var list<string> */
    public private(set) array $dropIndexes = [];

    public function __construct(
        public readonly string $tableName,
    ) {}

    public function id(string $column = 'id'): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::BigInt);
        $def->unsigned()->autoIncrement()->primary();
        $this->columns[] = $def;
        $this->primaryKeys[] = $column;

        return $def;
    }

    public function uuid(string $column = 'id'): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Uuid);
        $def->length(36)->primary();
        $this->columns[] = $def;
        $this->primaryKeys[] = $column;

        return $def;
    }

    public function string(string $column, int $length = 255): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::String);
        $def->length($length);
        $this->columns[] = $def;

        return $def;
    }

    public function text(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Text);
        $this->columns[] = $def;

        return $def;
    }

    public function integer(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Integer);
        $this->columns[] = $def;

        return $def;
    }

    public function bigInteger(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::BigInt);
        $this->columns[] = $def;

        return $def;
    }

    public function smallInteger(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::SmallInt);
        $this->columns[] = $def;

        return $def;
    }

    public function float(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Float);
        $this->columns[] = $def;

        return $def;
    }

    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Decimal);
        $def->precision($precision, $scale);
        $this->columns[] = $def;

        return $def;
    }

    public function boolean(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Boolean);
        $this->columns[] = $def;

        return $def;
    }

    public function dateTime(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::DateTime);
        $this->columns[] = $def;

        return $def;
    }

    public function date(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Date);
        $this->columns[] = $def;

        return $def;
    }

    public function time(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Time);
        $this->columns[] = $def;

        return $def;
    }

    public function json(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Json);
        $this->columns[] = $def;

        return $def;
    }

    public function binary(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Binary);
        $this->columns[] = $def;

        return $def;
    }

    public function enum(string $column): ColumnDefinition
    {
        $def = new ColumnDefinition($column, ColumnType::Enum);
        $this->columns[] = $def;

        return $def;
    }

    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    public function softDeletes(string $column = 'deleted_at'): void
    {
        $this->dateTime($column)->nullable();
    }

    /**
     * Add an index.
     *
     * @param list<string> $columns
     */
    public function index(array $columns, ?string $name = null): self
    {
        $name ??= sprintf('idx_%s_%s', $this->tableName, implode('_', $columns));
        $this->indexes[] = new IndexDefinition($name, $columns);

        return $this;
    }

    /**
     * Add a unique index.
     *
     * @param list<string> $columns
     */
    public function unique(array $columns, ?string $name = null): self
    {
        $name ??= sprintf('uniq_%s_%s', $this->tableName, implode('_', $columns));
        $this->indexes[] = new IndexDefinition($name, $columns, true);

        return $this;
    }

    /**
     * Add a foreign key constraint.
     *
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function foreign(
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
        ?string $name = null,
    ): self {
        $name ??= sprintf('fk_%s_%s', $this->tableName, implode('_', $columns));
        $this->foreignKeys[] = new ForeignKeyDefinition(
            $name,
            $columns,
            $referencedTable,
            $referencedColumns,
            $onDelete,
            $onUpdate,
        );

        return $this;
    }

    /**
     * Drop a column (for table modification).
     */
    public function dropColumn(string $column): self
    {
        $this->dropColumns[] = $column;

        return $this;
    }

    /**
     * Drop an index (for table modification).
     */
    public function dropIndex(string $name): self
    {
        $this->dropIndexes[] = $name;

        return $this;
    }

}
