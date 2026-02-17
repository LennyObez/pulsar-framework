<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\ColumnMetadata;

/**
 * Result of diffing entity columns against database columns.
 */
#[Api(since: '1.0.0')]
final readonly class ColumnDiff
{
    /**
     * @param list<ColumnMetadata> $addColumns  Columns to add
     * @param list<string>         $dropColumns Column names to drop
     */
    public function __construct(
        public array $addColumns,
        public array $dropColumns,
    ) {}
}
