<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Complete table definition for CREATE TABLE operations.
 */
#[Api(since: '1.0.0')]
final readonly class TableDefinition
{
    /**
     * @param list<SchemaColumn> $columns
     * @param list<SchemaIndex> $indexes
     * @param list<SchemaForeignKey> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {}
}
