<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Api;

/**
 * Readonly value object representing a logged SQL statement.
 *
 * Raw SQL bindings are never stored: only a binding hash is recorded
 * for correlation purposes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SqlLogEntry
{
    public function __construct(
        public string $normalizedSql,
        public string $bindingHash,
        public float $durationMs,
        public int $rowCount,
        public QueryClassification $classification,
        public string $sensitivityLevel,
    ) {}
}
