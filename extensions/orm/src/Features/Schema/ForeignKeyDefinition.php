<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Pulsar\Api\Api;

/**
 * Schema foreign key constraint definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForeignKeyDefinition
{
    /**
     * @param string $name Constraint name
     * @param list<string> $columns Local columns
     * @param string $referencedTable Referenced table
     * @param list<string> $referencedColumns Referenced columns
     * @param string $onDelete ON DELETE action (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * @param string $onUpdate ON UPDATE action
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public string $onDelete = 'RESTRICT',
        public string $onUpdate = 'RESTRICT',
    ) {}
}
