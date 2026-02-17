<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Result of attribution analysis for a single channel/source.
 */
#[Api(since: '1.0.0')]
final readonly class AttributionResult
{
    /**
     * @param string $source Traffic source (channel name)
     * @param int $conversions Number of conversions attributed to this source
     * @param float $revenue Revenue attributed to this source
     * @param float $weight Attribution weight (0.0 - 1.0) for the model used
     */
    public function __construct(
        public string $source,
        public int $conversions,
        public float $revenue = 0.0,
        public float $weight = 1.0,
    ) {}
}
