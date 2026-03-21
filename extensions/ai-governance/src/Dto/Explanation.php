<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Structured explanation of an AI-assisted decision.
 *
 * ISO 42001:2023 Clause 8.4 and Annex A control A.8.5 require transparency
 * and explainability for AI-assisted decisions.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Explanation
{
    /**
     * @param non-empty-string $decisionId The decision being explained
     * @param non-empty-string $modelId The model that made the decision
     * @param non-empty-string $summary Human-readable explanation
     * @param list<DecisionFactor> $factors Contributing factors to the decision
     * @param float $confidence Model confidence score (0.0–1.0)
     * @param list<non-empty-string> $alternativesConsidered Other options the model evaluated
     */
    public function __construct(
        public string $decisionId,
        public string $modelId,
        public string $summary,
        public array $factors,
        public float $confidence,
        public DateTimeImmutable $generatedAt,
        public array $alternativesConsidered = [],
    ) {}
}
