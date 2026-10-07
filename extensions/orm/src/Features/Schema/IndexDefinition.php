<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Schema;

use Pulsar\Api\Api;

/**
 * Schema index definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IndexDefinition
{
    /**
     * @param string $name Index name
     * @param list<string> $columns Columns in the index
     * @param bool $unique Whether this is a unique index
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {}
}
