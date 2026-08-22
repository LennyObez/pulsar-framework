<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Complete table definition for CREATE TABLE operations.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TableDefinition
{
    /**
     * @param list<SchemaColumn> $columns
     * @param list<SchemaIndex> $indexes
     * @param list<SchemaForeignKey> $foreignKeys
     * @param ?SchemaCollation $collation How every character column in this table
     *        compares unless it says otherwise. Null accepts the server's default, which
     *        on MySQL is case- and accent-insensitive — see {@see SchemaCollation} for
     *        what that costs a table keyed on identifiers, and for why a collation is the
     *        only table option this layer offers.
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes = [],
        public array $foreignKeys = [],
        public ?SchemaCollation $collation = null,
    ) {}
}
