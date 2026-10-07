<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\ColumnType;

/**
 * Schema column definition for table creation/modification.
 * @api
 */
#[Api(since: '1.0.0')]
final class ColumnDefinition
{
    public private(set) bool $nullable = false;
    public private(set) mixed $default = null;
    public private(set) bool $hasDefault = false;
    public private(set) bool $unsigned = false;
    public private(set) bool $autoIncrement = false;
    public private(set) bool $primaryKey = false;
    public private(set) bool $unique = false;
    public private(set) ?int $length = null;
    public private(set) ?int $precision = null;
    public private(set) ?int $scale = null;
    public private(set) ?string $after = null;

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

}
