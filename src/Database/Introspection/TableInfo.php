<?php

declare(strict_types=1);

namespace Pulsar\Database\Introspection;

use Pulsar\Api\Api;

/**
 * Metadata for a database table.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TableInfo
{
    public function __construct(
        public string $name,
        public ?string $schema = null,
    ) {}
}
