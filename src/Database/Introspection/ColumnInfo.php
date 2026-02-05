<?php

declare(strict_types=1);

namespace Pulsar\Database\Introspection;

use Pulsar\Api\Api;

/**
 * Metadata for a database column.
 */
#[Api(since: '1.0.0')]
final readonly class ColumnInfo
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $isPrimaryKey,
        public ?string $default,
    ) {}
}
