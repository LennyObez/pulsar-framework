<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use Pulsar\Api\Api;

/**
 * A single contributing factor to an AI-assisted decision.
 */
#[Api(since: '1.0.0')]
final readonly class DecisionFactor
{
    /**
     * @param non-empty-string $name Name of the factor
     * @param float $weight Relative weight/importance of this factor (0.0–1.0)
     * @param non-empty-string $description How this factor influenced the decision
     */
    public function __construct(
        public string $name,
        public float $weight,
        public string $description,
    ) {}
}
