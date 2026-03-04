<?php

declare(strict_types=1);

namespace Pulsar\Observability\Rum;

use Pulsar\Api\Api;

/**
 * Result of processing a batch of RUM metrics.
 */
#[Api(since: '1.0.0')]
final readonly class RumCollectionResult
{
    public function __construct(
        public int $accepted,
        public int $rejected,
    ) {}
}
