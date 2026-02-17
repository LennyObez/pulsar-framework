<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Result of evaluating a funnel over a date range.
 */
#[Api(since: '1.0.0')]
final readonly class FunnelResult
{
    /**
     * @param string $funnelId Funnel definition ID
     * @param list<FunnelStepResult> $steps Per-step visitor counts and drop-off
     * @param float $overallConversionRate Percentage of visitors completing all steps
     */
    public function __construct(
        public string $funnelId,
        public array $steps,
        public float $overallConversionRate,
    ) {}
}
