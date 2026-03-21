<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Fluent builder for a single column definition.
 *
 * Instances are returned from {@see Blueprint} column methods and allow
 * chaining modifiers: `->nullable()`, `->default(...)`, `->unique()`, etc.
 * @api
 */
#[Api(since: '1.0.0')]
final class ColumnBuilder
{
    private bool $nullable = false;
    private bool $unique = false;
    private int|float|string|bool|null $default = null;
    private bool $hasDefault = false;
    private ?SchemaDefaultExpression $defaultExpression = null;
    private bool $unsignedOverride = false;
    private bool $autoIncrementOverride = false;
    private ?string $comment = null;

    /**
     * @param list<string> $enumValues
     */
    public function __construct(
        private readonly string $name,
        private readonly SchemaColumnType $type,
        private readonly bool $primaryKey = false,
        private readonly bool $autoIncrement = false,
        private readonly bool $unsigned = false,
        private readonly ?int $length = null,
        private readonly ?int $precision = null,
        private readonly ?int $scale = null,
        private readonly array $enumValues = [],
    ) {}

    /**
     * Mark the column as nullable.
     */
    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Set a default value for the column.
     */
    public function default(int|float|string|bool|null $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Set a raw SQL default expression (e.g. CURRENT_TIMESTAMP).
     */
    public function defaultExpression(SchemaDefaultExpression $expression): self
    {
        $this->defaultExpression = $expression;

        return $this;
    }

    /**
     * Add a UNIQUE constraint on this column.
     */
    public function unique(bool $unique = true): self
    {
        $this->unique = $unique;

        return $this;
    }

    /**
     * Mark the column as unsigned (MySQL only; silently ignored on other drivers).
     */
    public function unsigned(bool $unsigned = true): self
    {
        $this->unsignedOverride = $unsigned;

        return $this;
    }

    /**
     * Mark the column as auto-incrementing.
     */
    public function autoIncrement(bool $autoIncrement = true): self
    {
        $this->autoIncrementOverride = $autoIncrement;

        return $this;
    }

    /**
     * Set a comment on the column (stored in DDL metadata).
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * Build the final SchemaColumn value object.
     */
    public function build(): SchemaColumn
    {
        return new SchemaColumn(
            name: $this->name,
            type: $this->type,
            nullable: $this->nullable,
            primaryKey: $this->primaryKey,
            autoIncrement: $this->autoIncrementOverride || $this->autoIncrement,
            unsigned: $this->unsignedOverride || $this->unsigned,
            unique: $this->unique,
            default: $this->default,
            hasDefault: $this->hasDefault,
            defaultExpression: $this->defaultExpression,
            length: $this->length,
            precision: $this->precision,
            scale: $this->scale,
            enumValues: $this->enumValues,
            comment: $this->comment,
        );
    }
}
