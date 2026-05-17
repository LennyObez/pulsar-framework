<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Foreign key definition for schema DDL operations.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SchemaForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public SchemaReferentialAction $onDelete = SchemaReferentialAction::Restrict,
        public SchemaReferentialAction $onUpdate = SchemaReferentialAction::Restrict,
    ) {}
}
