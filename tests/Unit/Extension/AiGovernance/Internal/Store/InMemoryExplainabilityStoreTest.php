<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Internal\Store;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore;

#[CoversClass(InMemoryExplainabilityStore::class)]
final class InMemoryExplainabilityStoreTest extends TestCase
{
    private InMemoryExplainabilityStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryExplainabilityStore();
    }

    #[Test]
    public function recordAndRetrieveExplanation(): void
    {
        $explanation = $this->makeExplanation('dec-1', 'model-a');
        $this->store->record($explanation);

        $retrieved = $this->store->explain('dec-1');

        self::assertNotNull($retrieved);
        self::assertSame('dec-1', $retrieved->decisionId);
        self::assertSame('model-a', $retrieved->modelId);
    }

    #[Test]
    public function explainReturnsNullForUnknownDecision(): void
    {
        self::assertNull($this->store->explain('nonexistent'));
    }

    #[Test]
    public function getByModelFiltersCorrectly(): void
    {
        $this->store->record($this->makeExplanation('dec-1', 'model-a'));
        $this->store->record($this->makeExplanation('dec-2', 'model-b'));
        $this->store->record($this->makeExplanation('dec-3', 'model-a'));

        $modelAExplanations = $this->store->getByModel('model-a');

        self::assertCount(2, $modelAExplanations);
    }

    #[Test]
    public function getByModelReturnsEmptyWhenNoMatch(): void
    {
        $this->store->record($this->makeExplanation('dec-1', 'model-a'));

        self::assertCount(0, $this->store->getByModel('model-x'));
    }

    #[Test]
    public function recordOverwritesExistingDecision(): void
    {
        $first = $this->makeExplanation('dec-1', 'model-a', confidence: 0.5);
        $second = $this->makeExplanation('dec-1', 'model-a', confidence: 0.95);

        $this->store->record($first);
        $this->store->record($second);

        $retrieved = $this->store->explain('dec-1');
        self::assertNotNull($retrieved);
        self::assertSame(0.95, $retrieved->confidence);
    }

    #[Test]
    public function explanationPreservesFactors(): void
    {
        $factors = [
            new DecisionFactor('credit_score', 0.4, 'High credit score indicates low risk'),
            new DecisionFactor('income', 0.3, 'Stable income supports approval'),
        ];

        $explanation = new Explanation(
            decisionId: 'dec-100',
            modelId: 'model-loan',
            summary: 'Loan approved based on credit score and income',
            factors: $factors,
            confidence: 0.87,
            generatedAt: new DateTimeImmutable(),
            alternativesConsidered: ['manual review', 'conditional approval'],
        );

        $this->store->record($explanation);

        $retrieved = $this->store->explain('dec-100');
        self::assertNotNull($retrieved);
        self::assertCount(2, $retrieved->factors);
        self::assertSame('credit_score', $retrieved->factors[0]->name);
        self::assertSame(0.4, $retrieved->factors[0]->weight);
        self::assertCount(2, $retrieved->alternativesConsidered);
    }

    /**
     * @param non-empty-string $decisionId
     * @param non-empty-string $modelId
     */
    private function makeExplanation(
        string $decisionId,
        string $modelId,
        float $confidence = 0.85,
    ): Explanation {
        return new Explanation(
            decisionId: $decisionId,
            modelId: $modelId,
            summary: "Decision $decisionId explanation",
            factors: [new DecisionFactor('factor1', 0.5, 'Test factor')],
            confidence: $confidence,
            generatedAt: new DateTimeImmutable(),
        );
    }
}
