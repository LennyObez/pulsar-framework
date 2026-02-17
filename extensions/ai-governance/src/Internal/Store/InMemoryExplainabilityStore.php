<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;
use Pulsar\Extension\AiGovernance\Dto\Explanation;

use function array_filter;
use function array_values;

/**
 * In-memory explainability store for development and testing.
 */
#[Internal(reason: 'Development store; production deployments should use a persistent implementation')]
final class InMemoryExplainabilityStore implements ExplainabilityInterface
{
    /** @var array<string, Explanation> Keyed by decision ID */
    private array $explanations = [];

    #[Override]
    public function explain(string $decisionId): ?Explanation
    {
        return $this->explanations[$decisionId] ?? null;
    }

    #[Override]
    public function record(Explanation $explanation): void
    {
        $this->explanations[$explanation->decisionId] = $explanation;
    }

    #[Override]
    public function getByModel(string $modelId): array
    {
        return array_values(array_filter(
            $this->explanations,
            static fn(Explanation $e): bool => $e->modelId === $modelId,
        ));
    }
}
