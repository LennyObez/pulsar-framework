<?php

declare(strict_types=1);

namespace Pulsar\Database\QueryLog;

use Pulsar\Api\Api;

/**
 * A single recorded database query with timing and context.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueryLogEntry
{
    /**
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public float $durationMs,
        public ?string $callerFile = null,
        public ?int $callerLine = null,
    ) {}
}
