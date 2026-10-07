<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use Pulsar\Api\Api;

/**
 * Result of a single purge operation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PurgeResult
{
    public function __construct(
        public string $category,
        public int $purgedCount,
        public bool $dryRun,
        public float $durationMs,
    ) {}
}
