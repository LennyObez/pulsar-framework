<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Fluent builder for a foreign-key column + constraint pair.
 *
 * Usage:
 * ```php
 * $table->foreignId('user_id')->references('id')->on('users');
 * ```
 */
#[Api(since: '1.0.0')]
final class ForeignIdBuilder
{
    private ?string $referencedColumn = null;
    private SchemaReferentialAction $onDelete = SchemaReferentialAction::Restrict;
    private SchemaReferentialAction $onUpdate = SchemaReferentialAction::Restrict;

    public function __construct(
        private readonly Blueprint $blueprint,
        private readonly ColumnBuilder $columnBuilder,
        private readonly string $column,
    ) {}

    /**
     * Set the referenced column name.
     */
    public function references(string $column): self
    {
        $this->referencedColumn = $column;

        return $this;
    }

    /**
     * Set the referenced table and register the foreign key constraint.
     */
    public function on(string $table): self
    {
        $referencedColumn = $this->referencedColumn ?? 'id';
        $fkName = sprintf('fk_%s_%s', $this->blueprint->table, $this->column);

        $this->blueprint->foreign(
            name: $fkName,
            columns: [$this->column],
            referencedTable: $table,
            referencedColumns: [$referencedColumn],
            onDelete: $this->onDelete,
            onUpdate: $this->onUpdate,
        );

        return $this;
    }

    /**
     * Set the ON DELETE action.
     */
    public function onDelete(SchemaReferentialAction $action): self
    {
        $this->onDelete = $action;

        return $this;
    }

    /**
     * Set the ON UPDATE action.
     */
    public function onUpdate(SchemaReferentialAction $action): self
    {
        $this->onUpdate = $action;

        return $this;
    }

    /**
     * Mark the column as nullable.
     */
    public function nullable(bool $nullable = true): self
    {
        $this->columnBuilder->nullable($nullable);

        return $this;
    }

    /**
     * Set a default value for the column.
     */
    public function default(int|float|string|bool|null $value): self
    {
        $this->columnBuilder->default($value);

        return $this;
    }
}
