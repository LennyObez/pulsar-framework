<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\ColumnType;

/**
 * Schema column definition for table creation/modification.
 */
#[Api(since: '1.0.0')]
final class ColumnDefinition
{
    private bool $nullable = false;
    private mixed $default = null;
    private bool $hasDefault = false;
    private bool $unsigned = false;
    private bool $autoIncrement = false;
    private bool $primaryKey = false;
    private bool $unique = false;
    private ?int $length = null;
    private ?int $precision = null;
    private ?int $scale = null;
    private ?string $after = null;

    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
    ) {}

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function unsigned(bool $unsigned = true): self
    {
        $this->unsigned = $unsigned;

        return $this;
    }

    public function autoIncrement(bool $auto = true): self
    {
        $this->autoIncrement = $auto;

        return $this;
    }

    public function primary(): self
    {
        $this->primaryKey = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function length(int $length): self
    {
        $this->length = $length;

        return $this;
    }

    public function precision(int $precision, int $scale = 0): self
    {
        $this->precision = $precision;
        $this->scale = $scale;

        return $this;
    }

    public function after(string $column): self
    {
        $this->after = $column;

        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function hasDefaultValue(): bool
    {
        return $this->hasDefault;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isPrimaryKey(): bool
    {
        return $this->primaryKey;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    public function getScale(): ?int
    {
        return $this->scale;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }
}
