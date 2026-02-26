<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use Pulsar\Api\Api;

/**
 * @psalm-api Public DTO returned from ExperimentService::getResults() and
 *            consumed by admin templates and user-land code.
 */
#[Api(since: '1.0.0')]
final readonly class ExperimentResult
{
    public function __construct(
        public string $variantId,
        public string $variantName,
        public int $impressions,
        public int $conversions,
        public float $conversionRate,
        public float $confidenceLevel,
    ) {}
}
