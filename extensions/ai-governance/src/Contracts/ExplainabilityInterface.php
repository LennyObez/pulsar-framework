<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\Explanation;

/**
 * Contract for AI decision explainability.
 *
 * ISO 42001:2023 Clause 8.4 and Annex A control A.8.5 require organizations
 * to provide transparency about how AI systems make decisions. Integrators
 * implement this interface for their specific AI provider.
 * @api
 */
#[Api(since: '1.0.0')]
interface ExplainabilityInterface
{
    /**
     * Generate an explanation for a specific AI-assisted decision.
     *
     * @param non-empty-string $decisionId The decision to explain
     */
    #[NoDiscard]
    public function explain(string $decisionId): ?Explanation;

    /**
     * Store a pre-computed explanation for later retrieval.
     */
    public function record(Explanation $explanation): void;

    /**
     * Retrieve all recorded explanations for a given model.
     *
     * @return list<Explanation>
     */
    #[NoDiscard]
    public function getByModel(string $modelId): array;
}
