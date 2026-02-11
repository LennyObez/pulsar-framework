<?php

declare(strict_types=1);

namespace Pulsar\Database\Schema;

use Pulsar\Api\Api;

/**
 * Index definition for schema DDL operations.
 */
#[Api(since: '1.0.0')]
final readonly class SchemaIndex
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {}
}
