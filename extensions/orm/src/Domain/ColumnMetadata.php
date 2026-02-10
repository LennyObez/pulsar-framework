<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Metadata for a single entity column mapping.
 */
#[Api(since: '1.0.0')]
final readonly class ColumnMetadata
{
    /**
     * @param string $propertyName PHP property name
     * @param string $columnName Database column name
     * @param ColumnType $type Column data type
     * @param bool $nullable Whether the column allows NULL
     * @param bool $isPrimaryKey Whether this is the primary key
     * @param bool $autoIncrement Whether the PK is auto-incrementing
     * @param bool $isVersion Whether this is the optimistic lock version column
     * @param bool $encrypted Whether this column is encrypted at rest
     * @param string|null $blindIndexColumn Blind index column name (if encrypted)
     * @param int|null $blindIndexHashLength Blind index hash length in bytes
     * @param bool $insertable Whether included in INSERT statements
     * @param bool $updatable Whether included in UPDATE statements
     * @param class-string|null $casterClass Custom caster class
     */
    public function __construct(
        public string $propertyName,
        public string $columnName,
        public ColumnType $type,
        public bool $nullable = false,
        public bool $isPrimaryKey = false,
        public bool $autoIncrement = false,
        public bool $isVersion = false,
        public bool $encrypted = false,
        public ?string $blindIndexColumn = null,
        public ?int $blindIndexHashLength = null,
        public bool $insertable = true,
        public bool $updatable = true,
        public ?string $casterClass = null,
    ) {}
}
