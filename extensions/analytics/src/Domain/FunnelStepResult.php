<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Result for a single funnel step evaluation.
 */
#[Api(since: '1.0.0')]
final readonly class FunnelStepResult
{
    /**
     * @param int $position Step position (1-based)
     * @param string $name Step name
     * @param int $visitors Visitors who reached this step
     * @param float $dropOffRate Percentage of visitors who dropped off at this step
     * @param float $conversionRate Percentage of visitors who continued to next step
     */
    public function __construct(
        public int $position,
        public string $name,
        public int $visitors,
        public float $dropOffRate,
        public float $conversionRate,
    ) {}
}
