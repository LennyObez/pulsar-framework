<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

use function count;
use function min;
use function round;

/**
 * In-memory impact assessment store for development and testing.
 */
#[Internal(reason: 'Development store; production deployments should use a persistent implementation')]
final class InMemoryImpactAssessmentStore implements AiImpactAssessmentInterface
{
    /** @var array<string, list<ImpactFinding>> Keyed by model ID */
    private array $findings = [];

    #[Override]
    public function assess(string $modelId, array $categories = []): void
    {
        // In-memory store does not perform automatic assessment;
        // findings are added manually via addFinding().
        // This satisfies the interface contract: a real implementation
        // would run analysis and populate findings.
        if (! isset($this->findings[$modelId])) {
            $this->findings[$modelId] = [];
        }
    }

    #[Override]
    public function hasAssessment(string $modelId): bool
    {
        return isset($this->findings[$modelId]);
    }

    /**
     * Add a finding for a model. Used by tests and manual assessments.
     */
    public function addFinding(string $modelId, ImpactFinding $finding): void
    {
        if (! isset($this->findings[$modelId])) {
            $this->findings[$modelId] = [];
        }

        $this->findings[$modelId][] = $finding;
    }

    #[Override]
    public function getFindings(string $modelId): array
    {
        return $this->findings[$modelId] ?? [];
    }

    #[Override]
    public function getRiskScore(string $modelId): float
    {
        $findings = $this->findings[$modelId] ?? [];

        if (count($findings) === 0) {
            return 0.0;
        }

        $severityWeights = [
            ImpactSeverity::Low->value => 1.0,
            ImpactSeverity::Medium->value => 3.0,
            ImpactSeverity::High->value => 6.0,
            ImpactSeverity::Critical->value => 10.0,
        ];

        $totalWeight = 0.0;

        foreach ($findings as $finding) {
            $totalWeight += $severityWeights[$finding->severity->value] ?? 1.0;
        }

        return min(10.0, round($totalWeight / count($findings), 2));
    }
}
