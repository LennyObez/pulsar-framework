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
     * @param ?SchemaCollation $collation How this column's text compares, overriding
     *        whatever the table defaults to. Null leaves the column on the table's
     *        default, which is the right answer whenever the whole table agrees; reach
     *        for this when one column inside a table must disagree — a token or a hash
     *        that has to stay case-sensitive in a table that is not. Ignored for
     *        non-character types, where MySQL rejects the clause outright.
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
        public ?SchemaCollation $collation = null,
    ) {}
}
