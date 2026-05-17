<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Column definition for schema DDL operations.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SchemaColumn
{
    /**
     * @param list<string> $enumValues
     */
    public function __construct(
        public string $name,
        public SchemaColumnType $type,
        public bool $nullable = false,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public bool $unsigned = false,
        public bool $unique = false,
        public int|float|string|bool|null $default = null,
        public bool $hasDefault = false,
        public ?SchemaDefaultExpression $defaultExpression = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public array $enumValues = [],
        public ?string $comment = null,
    ) {}
}
