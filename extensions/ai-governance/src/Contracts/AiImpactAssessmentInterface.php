<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;

/**
 * Contract for AI impact assessments.
 *
 * ISO 42001:2023 Clause 6.1.2 requires organizations to assess the potential
 * impacts of AI systems. Implementations evaluate an AI model against specific
 * impact categories and produce structured findings.
 * @api
 */
#[Api(since: '1.0.0')]
interface AiImpactAssessmentInterface
{
    /**
     * Perform an impact assessment on the specified AI model.
     *
     * @param non-empty-string $modelId The model to assess
     * @param list<ImpactCategory> $categories Categories to evaluate (all if empty)
     */
    public function assess(string $modelId, array $categories = []): void;

    /**
     * Retrieve findings from the most recent assessment of a model.
     *
     * @return list<ImpactFinding>
     */
    #[NoDiscard]
    public function getFindings(string $modelId): array;

    /**
     * Compute an overall risk score for a model based on its findings.
     *
     * Returns a score from 0.0 (no risk) to 10.0 (maximum risk).
     */
    #[NoDiscard]
    public function getRiskScore(string $modelId): float;
}
